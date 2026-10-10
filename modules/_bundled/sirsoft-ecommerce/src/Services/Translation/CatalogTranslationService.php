<?php

namespace Modules\Sirsoft\Ecommerce\Services\Translation;

use App\Helpers\PermissionHelper;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Enums\CatalogTranslationItemStatus as ItemStatus;
use Modules\Sirsoft\Ecommerce\Jobs\TranslateCatalogItem;
use Modules\Sirsoft\Ecommerce\Models\CatalogTranslationJob;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\TranslationJobRepositoryInterface;

class CatalogTranslationService
{
    public function __construct(private TranslationJobRepositoryInterface $jobs, private TranslationProviderInterface $provider, private StructuredTextTranslator $translator) {}

    public function start(int $owner, array $payload): CatalogTranslationJob
    {
        if (! $this->provider->configured()) {
            throw ValidationException::withMessages(['provider' => __('sirsoft-ecommerce::translation.not_configured')]);
        }
        $job = $this->jobs->createOnce($owner, [
            'id' => (string) Str::uuid(), 'request_id' => $payload['request_id'],
            'fingerprint' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE)),
            'kind' => $payload['kind'], 'entity_id' => $payload['entity_id'] ?? null, 'terms' => $payload['terms'],
            'items' => array_map(fn ($item) => [...$item, 'source' => (string) ($item['source'] ?? ''), 'status' => trim($item['source'] ?? '') === '' || (! $item['overwrite'] && trim($item['current'] ?? '') !== '') ? ItemStatus::Skipped->value : ItemStatus::Pending->value, 'attempts' => 0, 'result' => null, 'error' => null], $payload['items']),
        ]);
        $this->enqueue($job);

        return $job;
    }

    public function allowed(CatalogTranslationJob $job, int $owner): bool
    {
        if ((int) $job->owner_id !== $owner) {
            return false;
        }
        $user = User::find($owner);
        if (! $user || ! $user->isAdmin() || ! PermissionHelper::check('sirsoft-ecommerce.'.($job->kind === 'product' ? 'products' : 'categories').'.'.($job->entity_id ? 'update' : 'create'), $user)) {
            return false;
        }

        return ! $job->entity_id || ($job->kind === 'product' ? Product::whereKey($job->entity_id)->exists() : Category::whereKey($job->entity_id)->exists());
    }

    public function view(string $id, int $owner): CatalogTranslationJob
    {
        $job = $this->jobs->find($id);
        abort_unless($this->allowed($job, $owner), 403);
        // A killed worker is never silently re-sent to a paid provider.
        $job = $this->jobs->mutate($id, function ($job) {
            $items = $job->items;
            if ($job->created_at->lt(now()->subMinutes(15))) {
                foreach ($items as &$item) {
                    if (in_array($item['status'], [ItemStatus::Pending->value, ItemStatus::Processing->value], true)) {
                        $item['status'] = ItemStatus::Failed->value;
                        $item['error'] = 'timeout';
                    }
                }
                unset($item);
                $job->cancelled = true;
            }
            foreach ($items as &$item) {
                if ($item['status'] === ItemStatus::Processing->value && ($item['started_at'] ?? 0) < time() - 210) {
                    $item['status'] = ItemStatus::Failed->value;
                    $item['error'] = 'timeout';
                }
            }
            unset($item);
            $job->items = $items;
        });

        return $job;
    }

    public function retry(string $id, int $owner): CatalogTranslationJob
    {
        $this->view($id, $owner);
        $job = $this->jobs->mutate($id, function ($job) {
            if ($job->cancelled) {
                return;
            }
            $items = $job->items;
            foreach ($items as &$item) {
                if ($item['status'] === ItemStatus::Failed->value && $item['attempts'] < 3) {
                    $item['status'] = ItemStatus::Pending->value;
                    $item['error'] = null;
                }
            }
            unset($item);
            $job->items = $items;
        });
        $this->enqueue($job);

        return $job;
    }

    public function cancel(string $id, int $owner): CatalogTranslationJob
    {
        $this->view($id, $owner);

        return $this->jobs->mutate($id, function ($job) {
            $job->cancelled = true;
        });
    }

    private function enqueue(CatalogTranslationJob $job): void
    {
        if ($job->cancelled) {
            return;
        }
        foreach ($job->items as $item) {
            if ($item['status'] === ItemStatus::Pending->value) {
                TranslateCatalogItem::dispatch($job->id, $item['id'])->onConnection('ecommerce-translation')->onQueue('ecommerce-translation');
            }
        }
    }

    public function process(string $id, string $itemId): bool
    {
        $claimed = null;
        $deferred = false;
        $job = $this->jobs->mutate($id, function ($job) use ($itemId, &$claimed, &$deferred) {
            if ($job->cancelled || ! $this->allowed($job, (int) $job->owner_id)) {
                return;
            }
            if ($job->created_at->lt(now()->subMinutes(15))) {
                $job->cancelled = true;

                return;
            }
            $items = $job->items;
            $target = collect($items)->firstWhere('id', $itemId);
            if ($target && $target['status'] === ItemStatus::Pending->value) {
                foreach ($items as $other) {
                    if ($other['id'] === $itemId || $other['field'] !== $target['field'] || $other['locale'] !== $target['locale'] || $other['html'] !== $target['html'] || $other['source'] !== $target['source']) {
                        continue;
                    }
                    // Repeated option labels use identical wording without matching by translated text.
                    if ($other['status'] === ItemStatus::Completed->value) {
                        foreach ($items as &$item) {
                            if ($item['id'] === $itemId) {
                                $item['status'] = ItemStatus::Completed->value;
                                $item['result'] = $other['result'];
                                break;
                            }
                        }
                        unset($item);
                        $job->items = $items;

                        return;
                    }
                    if ($other['status'] === ItemStatus::Processing->value && ($other['started_at'] ?? 0) > time() - 210) {
                        $deferred = true;

                        return;
                    }
                }
            }
            $processing = $this->jobs->lockOwnerAndCountProcessing((int) $job->owner_id, $job->id)
                + count(array_filter($items, fn ($item) => $item['status'] === ItemStatus::Processing->value && ($item['started_at'] ?? 0) > time() - 210));
            if ($processing >= 2 && collect($items)->contains(fn ($item) => $item['id'] === $itemId && $item['status'] === ItemStatus::Pending->value)) {
                $deferred = true;

                return;
            }
            foreach ($items as &$item) {
                if ($item['id'] === $itemId && $item['status'] === ItemStatus::Pending->value) {
                    $item['status'] = ItemStatus::Processing->value;
                    $item['attempts']++;
                    $item['started_at'] = time();
                    $claimed = $item;
                    break;
                }
            }
            unset($item);
            $job->items = $items;
        });
        if (! $claimed) {
            return ! $deferred;
        }
        try {
            $result = $this->translator->translate($claimed['source'], $claimed['locale'], $claimed['html'], $job->terms);
            $limit = match ($claimed['field']) {
                'name' => $job->kind === 'category' ? 100 : 200, 'description' => $job->kind === 'category' ? 255 : 65535, 'meta_title' => 200, 'meta_description', 'meta_keywords' => 500, 'option_name' => 200, default => 100
            };
            if (mb_strlen($result) > $limit) {
                throw new \RuntimeException('invalid_response');
            }
            if ($claimed['field'] === 'option_value' && str_contains($result, ',')) {
                throw new \RuntimeException('invalid_response');
            }
            $error = null;
        } catch (\Throwable $exception) {
            $result = null;
            $error = in_array($exception->getMessage(), ['not_configured', 'invalid_response', 'timeout'], true) ? $exception->getMessage() : 'provider_failed';
        }
        $this->jobs->mutate($id, function ($job) use ($claimed, $itemId, $result, $error) {
            if ($job->cancelled) {
                return;
            }
            $items = $job->items;
            foreach ($items as &$item) {
                if ($item['id'] === $itemId && $item['status'] === ItemStatus::Processing->value && $item['attempts'] === $claimed['attempts']) {
                    $item['status'] = $error ? ItemStatus::Failed->value : ItemStatus::Completed->value;
                    $item['result'] = $result;
                    $item['error'] = $error;
                    break;
                }
            }
            unset($item);
            $job->items = $items;
        });

        return true;
    }
}

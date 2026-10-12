<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use App\Contracts\Extension\StorageInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Plugins\Yutiv\LiveCommerce\Contracts\LiveRepositoryInterface;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LiveMediaService extends LiveWorkflow
{
    public function __construct(LiveRepositoryInterface $records, private readonly StorageInterface $storage)
    {
        parent::__construct($records);
    }

    public function save(int $tenant, int $actor, string $kind, ?int $productId, UploadedFile $image): array
    {
        $model = $kind === 'product' ? $this->row(E::Product, $tenant, $productId) : LiveTenant::findOrFail($tenant);
        $name = Str::uuid().'.'.$image->extension();
        $path = $tenant.'/'.$name;
        abort_unless($this->storage->put('images', $path, file_get_contents($image->getRealPath())), 503, __('yutiv-live_commerce::live.errors.e_d3ba2c345a'));
        try {
            $this->records->transaction(function () use ($tenant, $actor, $kind, $model, $path) {
                LiveTenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
                $model->fill([$kind === 'product' ? 'image_path' : 'logo_path' => $path])->save();
                $this->audit($tenant, $actor, 'media.saved', ['kind' => $kind, 'record_id' => $model->id]);
            });
        } catch (\Throwable $error) {
            $this->storage->delete('images', $path);
            throw $error;
        }

        return ['saved' => true, 'image_url' => '/live-media/'.$kind.'/'.($kind === 'product' ? $model->id : 'logo')];
    }

    public function show(int $tenant, string $kind, string $record): StreamedResponse
    {
        $model = $kind === 'product' ? $this->row(E::Product, $tenant, (int) $record) : LiveTenant::findOrFail($tenant);
        if ($kind === 'product' && ! $model->published) {
            LiveContext::authorize('catalog');
        }
        $path = $model->{$kind === 'product' ? 'image_path' : 'logo_path'};
        abort_unless($path && str_starts_with($path, $tenant.'/'), 404);

        return $this->storage->response('images', $path, basename($path), ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=60']) ?? abort(404);
    }
}

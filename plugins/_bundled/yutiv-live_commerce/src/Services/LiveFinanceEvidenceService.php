<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use App\Contracts\Extension\StorageInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Plugins\Yutiv\LiveCommerce\Contracts\LiveRepositoryInterface;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Symfony\Component\HttpFoundation\HeaderUtils;

class LiveFinanceEvidenceService extends LiveWorkflow
{
    public function __construct(LiveRepositoryInterface $records, private readonly StorageInterface $storage)
    {
        parent::__construct($records);
    }

    public function upload(int $tenant, int $actor, array $d, UploadedFile $file): array
    {
        $entity = $d['source_kind'] === 'expense' ? E::Expense : E::MoneyTransfer;
        $this->row($entity, $tenant, $d['source_id']);
        $raw = file_get_contents($file->getRealPath());
        $digest = hash('sha256', $raw);
        $path = 'finance/'.$tenant.'/'.Str::uuid().'.encrypted';
        abort_unless($this->storage->put('attachments', $path, encrypt(base64_encode($raw))), 503, __('yutiv-live_commerce::live.finance_storage'));
        try {
            $result = $this->once($tenant, 'finance-attachment:'.$d['operation_key'], $d + ['digest' => $digest], function () use ($tenant, $actor, $d, $path, $digest, $file) {
                $row = $this->records->create(E::FinanceAttachment, $tenant, ['source_kind' => \Plugins\Yutiv\LiveCommerce\Enums\EvidenceSourceKind::from($d['source_kind'])->value, 'source_id' => $d['source_id'], 'path' => $path, 'name' => mb_substr(basename($file->getClientOriginalName()), 0, 200), 'mime' => $file->getMimeType(), 'digest' => $digest, 'actor_id' => $actor]);
                $this->audit($tenant, $actor, 'finance.evidence_added', ['attachment_id' => $row->id, 'source_kind' => \Plugins\Yutiv\LiveCommerce\Enums\EvidenceSourceKind::from($d['source_kind'])->value, 'source_id' => $d['source_id']]);

                return ['attachment_id' => $row->id];
            });
            $stored = $this->row(E::FinanceAttachment, $tenant, $result['attachment_id']);
            if ($stored->path !== $path) {
                $this->storage->delete('attachments', $path);
            }

return $result;
        } catch (\Throwable $error) {
            $this->storage->delete('attachments', $path);
            throw $error;
        }
    }

    public function download(int $tenant, int $id)
    {
        $row = $this->row(E::FinanceAttachment, $tenant, $id);
        $this->row($row->source_kind === 'expense' ? E::Expense : E::MoneyTransfer, $tenant, $row->source_id);
        abort_unless(str_starts_with($row->path, 'finance/'.$tenant.'/'), 404);
        $cipher = $this->storage->get('attachments', $row->path);
        abort_unless($cipher, 404);
        $data = base64_decode(decrypt($cipher), true);
        abort_unless($data !== false && hash_equals($row->digest, hash('sha256', $data)), 409);

        return response($data)->header('Content-Type', $row->mime)->header('Content-Disposition',HeaderUtils::makeDisposition('attachment',$row->name,'evidence'))->header('Cache-Control','private, no-store')->header('X-Content-Type-Options','nosniff');
    }
}

<?php

namespace Modules\Yutiv\PersonalStore\Http\Controllers;

use App\Enums\AttachmentSourceType;
use App\Http\Controllers\Api\Base\AuthBaseController;
use App\Http\Resources\AttachmentResource;
use App\Services\AttachmentService;
use Illuminate\Http\JsonResponse;
use Modules\Yutiv\PersonalStore\Http\Requests\UploadStoreMediaRequest;
use Modules\Yutiv\PersonalStore\Models\Store;
use Modules\Yutiv\PersonalStore\Repositories\Contracts\StoreRepositoryInterface;

class OperatorMediaController extends AuthBaseController
{
    public function upload(UploadStoreMediaRequest $request, StoreRepositoryInterface $stores, AttachmentService $attachments): JsonResponse
    {
        // 대상 모델/스토어는 클라이언트 입력을 받지 않고 인증된 운영자에게 고정합니다.
        $id = $stores->mediaStoreId($request->user()->id);
        $attachment = $attachments->upload($request->file('file'), Store::class, $id, $request->validated('collection'), AttachmentSourceType::Module, 'yutiv-personal_store');

        return $this->successWithResource('attachment.upload_success', new AttachmentResource($attachment), 201);
    }
}

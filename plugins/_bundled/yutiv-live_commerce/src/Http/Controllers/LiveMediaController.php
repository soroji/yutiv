<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Controllers;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Plugins\Yutiv\LiveCommerce\Http\Requests\LiveMediaRequest;
use Plugins\Yutiv\LiveCommerce\Services\LiveMediaService;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LiveMediaController
{
    public function __construct(private readonly LiveMediaService $media) {}

    public function store(LiveMediaRequest $request): JsonResponse
    {
        return ResponseHelper::success('common.success', $this->media->save(LiveContext::requireTenant()->id, LiveContext::user()->id, $request->route('kind'), $request->validated('product_id'), $request->file('image')));
    }

    public function show(string $kind, string $record): StreamedResponse
    {
        return $this->media->show(LiveContext::requireTenant()->id, $kind, $record);
    }
}

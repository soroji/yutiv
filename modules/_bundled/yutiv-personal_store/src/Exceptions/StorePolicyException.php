<?php

namespace Modules\Yutiv\PersonalStore\Exceptions;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;

/** Expected policy rejection: do not log request tokens or render exception traces. */
class StorePolicyException extends \DomainException
{
    public function render(): JsonResponse
    {
        return ResponseHelper::error('yutiv-personal_store::messages.unavailable', 422);
    }

    public function report(): bool
    {
        return true;
    }
}

<?php

namespace Plugins\Yutiv\StorefrontSupport\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Plugins\Yutiv\StorefrontSupport\Support\PublicBusinessInfo;

final class BusinessInfoResource extends BaseApiResource
{
    public function toArray(Request $request): array
    {
        return PublicBusinessInfo::project(is_array($this->resource) ? $this->resource : []);
    }
}

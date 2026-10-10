<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\TranslationConnectionStatus;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\CatalogTranslationRequest;
use Modules\Sirsoft\Ecommerce\Services\Translation\CatalogTranslationService;
use Modules\Sirsoft\Ecommerce\Services\Translation\TranslationProviderInterface;

class CatalogTranslationController extends AdminBaseController
{
    public function __construct(private CatalogTranslationService $service, private TranslationProviderInterface $provider) {}

    public function configuration(Request $request)
    {
        abort_unless(collect(['products.create', 'products.update', 'categories.create', 'categories.update', 'settings.read'])->contains(fn ($permission) => PermissionHelper::check('sirsoft-ecommerce.'.$permission, $request->user())), 403);

        $config = config('sirsoft-ecommerce-translation');
        $configured = $this->provider->configured();
        $status = TranslationConnectionStatus::detect($config, $configured)->value;

        return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'translation.ready', ['configured' => $configured, 'status' => $status, 'settings_url' => '/admin/ecommerce/settings?tab=language_currency', 'queue' => 'ecommerce-translation']);
    }

    public function store(CatalogTranslationRequest $request)
    {
        return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'translation.ready', $this->service->start((int) $request->user()->id, $request->validated()));
    }

    public function show(Request $request, string $id)
    {
        return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'translation.ready', $this->service->view($id, (int) $request->user()->id));
    }

    public function retry(Request $request, string $id)
    {
        return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'translation.ready', $this->service->retry($id, (int) $request->user()->id));
    }

    public function cancel(Request $request, string $id)
    {
        return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'translation.ready', $this->service->cancel($id, (int) $request->user()->id));
    }
}

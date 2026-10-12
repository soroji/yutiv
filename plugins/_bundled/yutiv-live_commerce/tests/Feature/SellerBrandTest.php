<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;

use Plugins\Yutiv\LiveCommerce\Contracts\LiveRepositoryInterface;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideConfig;

class SellerBrandTest extends TeeWideMainDesignTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config([TeeWideConfig::KEY.'.brand_tenant' => 'golfif']);
    }

    public function test_brands_follow_server_context_not_query_or_previous_request(): void
    {
        LiveTenant::where('slug', 'golfif')->update(['name' => 'Golfif']);
        $this->seedLiveTenant(['slug' => 'other-shop', 'name' => 'Other & Brand']);
        foreach (['/', '/login', '/register'] as $path) {
            $body = $this->get('http://'.self::ROOT_HOST.$path.'?tenant=other-shop')->assertOk()->getContent();
            $this->assertStringContainsString('Golfif', $body);
            $visible = substr($body, 0, strpos($body, '<style>')).substr($body, strpos($body, '</style>') + 8);
            $this->assertDoesNotMatchRegularExpression('/TeeWide|YUTIV|yutiv\.com/', $visible);
        }
        $this->get('http://'.self::LIVE_HOST.'/other-shop')->assertOk()->assertSee('Other &amp; Brand', false);
        $this->get('http://'.self::ROOT_HOST.'/')->assertOk()->assertSee('Golfif');
        config([TeeWideConfig::KEY.'.brand_tenant' => 'other-shop']);
        $this->get('http://'.self::ROOT_HOST.'/')->assertOk()->assertSee('Other &amp; Brand', false)->assertDontSee('Golfif');
        LiveTenant::where('slug', 'other-shop')->update(['status' => 'suspended']);
        $this->get('http://'.self::ROOT_HOST.'/')->assertNotFound();
        $this->get('http://'.self::LIVE_HOST.'/other-shop')->assertNotFound();
    }

    public function test_brand_write_requires_owner_and_valid_name(): void
    {
        $tenant = LiveTenant::where('slug', 'golfif')->firstOrFail();
        $user = $this->makeTeeWideUser(['email' => 'brand-owner@example.test']);
        $tenant->update(['owner_user_id' => $user->id]);
        app(LiveRepositoryInterface::class)->create(LiveEntity::Staff, $tenant->id, ['user_id' => $user->id, 'role' => 'owner', 'active' => true]);
        $this->seedLiveTenant(['slug' => 'other-shop', 'name' => 'Other Brand']);
        $login = $this->post('http://'.self::ROOT_HOST.'/login', ['email' => $user->email, 'password' => self::TEST_USER_PASSWORD]);
        $cookie = $login->getCookie('teewide_session', false);
        $this->withUnencryptedCookie('teewide_session', $cookie->getValue())->withCredentials();
        $this->postJson('http://'.self::ROOT_HOST.'/account/brand/other-shop', ['name' => 'Stolen'])->assertForbidden();
        $this->postJson('http://'.self::ROOT_HOST.'/seller/tasks/brand', ['name' => '<script>bad</script>'])->assertUnprocessable();
        $this->postJson('http://'.self::ROOT_HOST.'/seller/tasks/brand', ['name' => str_repeat('a', 101)])->assertUnprocessable();
        $this->postJson('http://'.self::ROOT_HOST.'/seller/tasks/brand', ['name' => 'Golfif'])->assertOk();
        $this->assertSame('Golfif', $tenant->fresh()->name);
        $this->get('http://'.self::ROOT_HOST.'/seller/brand')->assertOk()->assertSee('Golfif')->assertSee('브랜드');
    }
}

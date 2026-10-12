<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;

use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;
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
        $this->get('http://'.self::ROOT_HOST.'/')->assertOk()->assertDontSee('Other &amp; Brand', false);
        $this->get('http://'.self::LIVE_HOST.'/other-shop')->assertNotFound();
    }

    public function test_brand_write_requires_owner_and_valid_name(): void
    {
        $this->memberResponse();
        $user = TeeWideUser::where('email', 'design-fixture@teewide.test')->firstOrFail();
        LiveTenant::where('slug', 'golfif')->update(['owner_user_id' => $user->id]);
        $this->seedLiveTenant(['slug' => 'other-shop', 'name' => 'Other Brand']);
        $this->post('http://'.self::ROOT_HOST.'/account/brand/other-shop', ['name' => 'Stolen'])->assertForbidden();
        $invalid = $this->post('http://'.self::ROOT_HOST.'/account/brand/golfif', ['name' => '<script>bad</script>'])->assertRedirect(route('teewide.account'));
        $cookie = $invalid->getCookie('teewide_session', false);
        $this->assertNotNull($cookie);
        $this->withUnencryptedCookie('teewide_session', $cookie->getValue())->get('http://'.self::ROOT_HOST.'/account')->assertOk()->assertSee('name 필드');
        $this->post('http://'.self::ROOT_HOST.'/account/brand/golfif', ['name' => str_repeat('a', 101)])->assertRedirect(route('teewide.account'));
        $this->post('http://'.self::ROOT_HOST.'/account/brand/golfif', ['name' => 'Golfif'])->assertRedirect(route('teewide.account'));
        $this->assertSame('Golfif', LiveTenant::where('slug', 'golfif')->value('name'));
        $this->get('http://'.self::ROOT_HOST.'/account')->assertOk()->assertSee('Golfif')->assertSee('고객에게 표시할 브랜드 이름');
    }
}

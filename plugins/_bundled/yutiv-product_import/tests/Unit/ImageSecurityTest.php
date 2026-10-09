<?php

namespace Plugins\Yutiv\ProductImport\Tests\Unit;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;
use Plugins\Yutiv\ProductImport\Support\ImageFetcher;
use Tests\TestCase;

class ImageSecurityTest extends TestCase
{
    protected array $requiredExtensions = ['sirsoft-ecommerce', 'yutiv-product_import'];

    public function createApplication()
    {
        $app = require dirname(__DIR__, 5).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    private function blocked(ImageFetcher $f, string $url): void
    {
        try {
            $f->inspect($url);
            $this->fail('Unsafe URL accepted: '.$url);
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public function test_private_reserved_metadata_and_transition_ips_are_blocked(): void
    {
        $f = new ImageFetcher;
        foreach (['127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.1.1', '169.254.169.254', '168.63.129.16', '100.64.1.1', '100.127.1.1', '224.1.1.1', '0.0.0.0', '::1', 'fc00::1', 'fe80::1', '::ffff:127.0.0.1', '64:ff9b::a00:1', '2002:7f00:1::'] as $ip) {
            $this->assertFalse($f->publicIp($ip), $ip);
        }
        $this->assertTrue($f->publicIp('8.8.8.8'));
    }

    public function test_scheme_credentials_obfuscated_ip_and_mixed_dns_are_blocked(): void
    {
        $f = new class extends ImageFetcher
        {
            protected function resolve(string $host): array
            {
                return ['8.8.8.8', '127.0.0.1'];
            }
        };
        foreach (['http://example.org/a.png', 'https://localhost/a.png', 'https://user:pass@example.org/a.png', 'https://example.org:8443/a.png', 'https://2130706433/a.png', 'https://example.org/a.png'] as $u) {
            $this->blocked($f, $u);
        }
    }

    public function test_curl_numeric_host_normalization_cannot_bypass_public_dns_pinning(): void
    {
        $f = new class extends ImageFetcher
        {
            protected function resolve(string $host): array
            {
                return ['8.8.8.8'];
            }
        };
        foreach (['2130706433', '127.1', '0177.0.0.1', '0x7f000001', '0x7f.0.0.1'] as $host) {
            $this->blocked($f, 'https://'.$host.'/image.png');
        }
    }

    public function test_redirect_to_internal_host_is_blocked_before_second_download_and_temp_file_is_removed(): void
    {
        $f = new class extends ImageFetcher
        {
            public int $calls = 0;

            protected function resolve(string $h): array
            {
                return $h === '127.0.0.1' ? ['127.0.0.1'] : ['8.8.8.8'];
            }

            protected function request(string $url, string $host, string $ip, string $path): array
            {
                $this->calls++;
                file_put_contents($path, 'redirect');

                return [302, 'https://127.0.0.1/secret'];
            }
        };
        $path = tempnam(sys_get_temp_dir(), 'redirect-test-');
        try {
            $f->fetch('https://example.org/photo.png', $path);
            $this->fail('Redirect SSRF accepted');
        } catch (ValidationException $e) {
            $this->assertSame(1, $f->calls);
            $this->assertFalse(is_file($path));
        }
    }

    public function test_relative_redirect_is_resolved_and_dns_is_pinned_at_every_hop(): void
    {
        $f = new class extends ImageFetcher
        {
            public array $calls = [];

            protected function resolve(string $h): array
            {
                return ['8.8.8.8'];
            }

            protected function request(string $url, string $host, string $ip, string $path): array
            {
                $this->calls[] = [$url, $host, $ip];
                file_put_contents($path, 'not an image');

                return count($this->calls) === 1 ? [302, '/next.png'] : [200, null];
            }
        };
        try {
            $f->fetch('https://example.org/start.png');
            $this->fail('Non-image accepted');
        } catch (ValidationException $e) {
            $this->assertSame('https://example.org/next.png', $f->calls[1][0]);
            $this->assertSame('8.8.8.8', $f->calls[0][2]);
            $this->assertSame('8.8.8.8', $f->calls[1][2]);
        }
    }
}

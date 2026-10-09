<?php

namespace Plugins\Yutiv\ProductImport\Support;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UploadProductImageRequest;

class ImageFetcher
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public function publicIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if ($ip === '168.63.129.16') {
            return false;
        } // Azure platform/metadata virtual address.
        if (str_contains($ip, ':')) {
            // Only native global unicast; reject mapped, NAT64, 6to4/Teredo and documentation ranges.
            $bin = inet_pton($ip);
            $head = unpack('n', substr($bin, 0, 2))[1];

            return ($head & 0xE000) === 0x2000 && $head !== 0x2002 && ! str_starts_with(strtolower($ip), '2001:');
        }
        $parts = explode('.', $ip);
        $n = (int) $parts[0];

        return $n > 0 && $n < 224 && ! ($n === 100 && (int) $parts[1] >= 64 && (int) $parts[1] <= 127);
    }

    protected function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        try {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        } catch (\Throwable) {
            $records = [];
        }

        return array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records ?: [])));
    }

    public function inspect(string $url): array
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\\\\]/', $url)) {
            Workbook::reject('이미지 URL에 공백 또는 잘못된 문자가 있습니다.');
        }
        $u = parse_url($url);
        if (! $u || ($u['scheme'] ?? '') !== 'https' || ! isset($u['host']) || isset($u['user']) || isset($u['pass']) || isset($u['fragment']) || ($u['port'] ?? 443) !== 443) {
            Workbook::reject('이미지는 인증정보 없는 HTTPS URL(443 포트)로 입력하세요.');
        }
        $host = trim(strtolower($u['host']), '[]');
        if (! filter_var($host, FILTER_VALIDATE_IP) && preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*$/', rtrim($host, '.'))) {
            Workbook::reject('축약·16진수·숫자 변형 IP 주소는 사용할 수 없습니다.');
        }
        if (! filter_var($host, FILTER_VALIDATE_IP) && ! filter_var(rtrim($host, '.'), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            Workbook::reject('이미지 호스트 이름을 확인하세요.');
        }
        if (! preg_match('/^[a-z0-9.:-]+$/', $host) || in_array($host, ['localhost', 'metadata.google.internal'], true) || preg_match('/\.(localhost|local|internal)$/', $host)) {
            Workbook::reject('내부망 이미지 주소는 사용할 수 없습니다.');
        }
        $ips = $this->resolve($host);
        if (! $ips) {
            Workbook::reject('이미지 주소의 DNS를 확인할 수 없습니다.');
        }
        foreach ($ips as $ip) {
            if (! $this->publicIp($ip)) {
                Workbook::reject('내부망·메타데이터·예약 주소의 이미지는 사용할 수 없습니다.');
            }
        }

        return [$host, $ips];
    }

    /** DNS pinning prevents a second DNS lookup/rebinding. Every redirect is inspected again. */
    protected function request(string $url, string $host, string $ip, string $path): array
    {
        $fp = fopen($path, 'wb');
        $ch = curl_init($url);
        $bytes = 0;
        $location = null;
        try {
            curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_PROXY => '', CURLOPT_RESOLVE => [$host.':443:'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)],
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$location) {
                    if (stripos($line, 'location:') === 0) {
                        $location = trim(substr($line, 9));
                    }

                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use ($fp, &$bytes) {
                    $bytes += strlen($chunk);
                    if ($bytes > self::MAX_BYTES) {
                        return 0;
                    }

                    return fwrite($fp, $chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($ok === false) {
                Workbook::reject('이미지 다운로드가 실패했거나 10MB/시간 제한을 초과했습니다.');
            }

            return [$code, $location];
        } finally {
            curl_close($ch);
            fclose($fp);
        }
    }

    public function fetch(string $url, ?string $downloadPath = null): UploadedFile
    {
        $path = $downloadPath ?? tempnam(sys_get_temp_dir(), 'yutiv-image-');
        try {
            for ($hop = 0; $hop <= 3; $hop++) {
                [$host,$ips] = $this->inspect($url);
                [$code,$location] = $this->request($url, $host, $ips[0], $path);
                if ($code >= 300 && $code < 400) {
                    if (! $location || $hop === 3) {
                        Workbook::reject('이미지 리다이렉트가 너무 많습니다.');
                    }
                    $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

                    continue;
                }
                if ($code !== 200) {
                    Workbook::reject('이미지 주소가 정상 응답하지 않습니다.');
                }
                $info = @getimagesize($path);
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
                $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                if (! $info || ! isset($types[$mime]) || $info['mime'] !== $mime || $info[0] > 10000 || $info[1] > 10000 || $info[0] * $info[1] > 20000000) {
                    Workbook::reject('JPEG/PNG/GIF/WebP 이미지(각 변 10000px, 2000만 픽셀 이하)를 사용하세요.');
                }
                $file = new UploadedFile($path, 'image.'.$types[$mime], $mime, null, true);
                $request = new UploadProductImageRequest;
                Validator::make(['file' => $file], $request->rules(), $request->messages())->validate();

                return $file;
            }
            Workbook::reject('이미지를 가져올 수 없습니다.');
        } catch (\Throwable $e) {
            if (is_file($path)) {
                unlink($path);
            }throw $e;
        }
    }
}

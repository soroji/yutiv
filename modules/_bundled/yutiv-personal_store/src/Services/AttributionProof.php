<?php

namespace Modules\Yutiv\PersonalStore\Services;

use Illuminate\Support\Facades\Crypt;
use Modules\Yutiv\PersonalStore\Exceptions\StorePolicyException;

/** 암호 인증된 증명과 서버 저장 참조를 구분합니다. 장바구니에 원문 토큰을 저장하지 않습니다. */
class AttributionProof
{
    public function issue(string $reference, int $expiresAt, string $purpose): string
    {
        return Crypt::encryptString(json_encode(['reference' => $reference, 'expires_at' => $expiresAt, 'purpose' => $purpose, 'nonce' => bin2hex(random_bytes(16))], JSON_THROW_ON_ERROR));
    }

    public function verify(?string $token, string $purpose): ?string
    {
        if ($token === null || $token === '') {
            return null;
        }
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
            if (($payload['purpose'] ?? null) !== $purpose || ! is_int($payload['expires_at'] ?? null) || $payload['expires_at'] <= time() || ! is_string($payload['reference'] ?? null)) {
                throw new \RuntimeException;
            }

            return $payload['reference'];
        } catch (\Throwable) {
            throw new StorePolicyException(__('yutiv-personal_store::messages.invalid_proof'));
        }
    }
}

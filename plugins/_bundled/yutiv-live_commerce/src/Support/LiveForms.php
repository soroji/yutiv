<?php

namespace Plugins\Yutiv\LiveCommerce\Support;

use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;

/** 관리 화면과 FormRequest가 공유하는 필드 목록. 입력은 이 목록으로 제한합니다. */
final class LiveForms
{
    public static function entity(string $key): E
    {
        return match ($key) {
            'products' => E::Product, 'options' => E::Option, 'channels' => E::Channel, 'showhosts' => E::Showhost,
            'broadcasts' => E::Broadcast, 'broadcast-items' => E::BroadcastItem, 'sale-links' => E::SaleLink, 'banks' => E::Bank, default => abort(404)
        };
    }

    public static function ability(string $key): string
    {
        return $key === 'banks' ? 'banks' : (in_array($key, ['broadcasts', 'broadcast-items', 'channels', 'showhosts', 'sale-links'], true) ? 'broadcast' : 'catalog');
    }

    public static function fields(string $key): array
    {
        $field = fn ($label, $type = 'text', $required = true, $choices = null) => compact('label', 'type', 'required', 'choices');

        return match ($key) {
            'products' => ['name' => $field('상품명'), 'code' => $field('상품코드'), 'description' => $field('설명', 'textarea', false), 'published' => $field('공개 노출', 'checkbox', false), 'sellable' => $field('판매 가능', 'checkbox', false)],
            'options' => ['product_id' => $field('상품 선택', 'select', true, E::Product), 'name' => $field('옵션명'), 'price' => $field('가격 (원)', 'number'), 'discount' => $field('단위 할인 (원)', 'number', false), 'active' => $field('판매 가능', 'checkbox', false)],
            'banks' => ['bank' => $field('은행'), 'account_number' => $field('계좌번호'), 'holder' => $field('예금주'), 'active' => $field('신규 주문 사용', 'checkbox', false), 'default' => $field('기본 계좌', 'checkbox', false)],
            'channels' => ['name' => $field('유튜브 채널 이름'), 'url' => $field('유튜브 채널 URL', 'url'), 'active' => $field('활성', 'checkbox', false)],
            'showhosts' => ['name' => $field('쇼호스트 이름'), 'active' => $field('활성', 'checkbox', false)],
            'broadcasts' => ['name' => $field('방송명'), 'channel_id' => $field('유튜브 채널', 'select', true, E::Channel), 'bank_account_id' => $field('입금계좌 (미지정 시 업체 기본)', 'select', false, E::Bank),
                'video_url' => $field('유튜브 방송 URL', 'url', false), 'starts_at' => $field('방송 시작 예정 (업체 시간)', 'datetime-local'), 'ends_at' => $field('방송 종료 예정 (업체 시간)', 'datetime-local'),
                'sale_starts_at' => $field('판매 시작 (업체 시간)', 'datetime-local'), 'sale_ends_at' => $field('판매 종료 (업체 시간)', 'datetime-local'),
                'state' => $field('방송 상태', 'choice', true, ['draft' => '준비', 'selling' => '판매 중', 'closed' => '종료']),
                'channel_percent' => $field('채널 운영자 수수료 (%)', 'text'), 'host_percent' => $field('쇼호스트 수수료 (%)', 'text'), 'host_shares' => $field('쇼호스트별 지급 지분', 'hosts', false)],
            'broadcast-items' => ['broadcast_id' => $field('방송 회차', 'select', true, E::Broadcast), 'option_id' => $field('상품/옵션', 'select', true, E::Option),
                'position' => $field('노출 순서', 'number'), 'active' => $field('판매 노출', 'checkbox', false), 'channel_percent' => $field('채널율 예외 (%)', 'text', false), 'host_percent' => $field('호스트율 예외 (%)', 'text', false)],
            'sale-links' => ['broadcast_id' => $field('방송 회차', 'select', true, E::Broadcast), 'channel_id' => $field('귀속 유튜브 채널', 'select', true, E::Channel),
                'showhost_id' => $field('실적 귀속 쇼호스트 (공동은 미지정)', 'select', false, E::Showhost), 'option_id' => $field('개별 상품 (방송 목록은 미지정)', 'select', false, E::Option),
                'contact' => $field('상담 경로', 'choice', true, ['kakao' => '카카오', 'telegram' => '텔레그램', 'other' => '기타']), 'expires_at' => $field('링크 만료 (업체 시간)', 'datetime-local', false), 'active' => $field('활성', 'checkbox', false)],
            default => [],
        };
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Plugins\Yutiv\LiveCommerce\Database\Seeders\LiveTenantSeeder;

/**
 * 초기 라이브 채널(GolfIF)을 넣는다.
 *
 * ── 왜 마이그레이션에서 시드하는가 ─────────────────────────────────────────
 * Phase 1-A 까지 GolfIF 는 설정 파일에 있었고 `live.teewide.com/golfif` 는 이미 공개된
 * 주소다. 마이그레이션만 돌리고 시드를 잊으면 **운영 중이던 채널이 404 가 된다.**
 * 그래서 스키마와 함께 옮겨 붙인다.
 *
 * 로직은 `LiveTenantSeeder` 에 있다 — 나중에 `db:seed` 로 따로 돌릴 수도 있어야 하고,
 * 멱등성 테스트도 그 클래스를 직접 태운다.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new LiveTenantSeeder)->run();
    }

    public function down(): void
    {
        // 이 마이그레이션이 넣은 채널만 지운다. 운영자가 추가한 채널은 남긴다.
        (new LiveTenantSeeder)->rollback();
    }
};

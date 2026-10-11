<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Extension\AbstractUpgradeStep;

/** @upgrade-path A 기존 주문의 NULL 원본 라인은 소급 리워드로 변환하지 않습니다. */
class Upgrade_1_3_0 extends AbstractUpgradeStep {}

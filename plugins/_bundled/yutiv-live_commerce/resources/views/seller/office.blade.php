@extends('teewide::layouts.operations', ['title' => $sellerBrand.' — '.$menuTitle])
@section('navigation')
@foreach ($menu as $key => $item)<a href="/seller/{{ $key }}" @if($section === $key) aria-current="page" @endif>{{ $item[0] }}</a>@endforeach
@endsection
@section('content')
<h1>{{ $menuTitle }}</h1><p class="live-help">{{ $tenant->name }} · {{ $tenant->timezone }} · 예약 기본 {{ $tenant->reservation_minutes }}분</p>
@if ($summary)
@if($section==='statistics')<form method="GET" class="live-panel live-grid">@foreach(['broadcast_id'=>'방송','channel_id'=>'채널','showhost_id'=>'쇼호스트 / 공동 참여','product_id'=>'상품','option_id'=>'상품 옵션'] as $field=>$label)<label>{{ $label }}<select name="{{ $field }}"><option value="">전체</option>@foreach($choices[$field] ?? [] as $choice)<option value="{{ $choice->id }}" @selected((string)request($field)===(string)$choice->id)>{{ $choice->name }}</option>@endforeach</select></label>@endforeach @foreach(['from','to','basis','period'] as $field)@if(request($field))<input type="hidden" name="{{ $field }}" value="{{ request($field) }}">@endif @endforeach<button class="live-button" type="submit">조건별 통계 조회</button></form>@endif
@if ($section === 'statistics')<form method="GET" class="live-panel live-grid"><div class="live-field"><label for="from">시작일</label><input type="date" id="from" name="from" value="{{ request('from', now()->subMonth()->format('Y-m-d')) }}"></div><div class="live-field"><label for="to">종료일</label><input type="date" id="to" name="to" value="{{ request('to', now()->format('Y-m-d')) }}"></div><div class="live-field"><label for="basis">집계 기준</label><select id="basis" name="basis"><option value="created_at">주문일</option><option value="paid_at" @selected(request('basis')==='paid_at')>입금일</option></select></div><div class="live-field"><label for="period">기간 단위</label><select id="period" name="period"><option value="day">일</option><option value="week" @selected(request('period')==='week')>주</option><option value="month" @selected(request('period')==='month')>월</option></select></div><button class="live-button" type="submit">통계 조회</button></form>@endif
<p class="live-help">{{ $summary['basis'] }} 기준 · {{ $summary['timezone'] }} · 주간은 월요일 시작. 채널별 매출과 쇼호스트별 매출은 동일 매출의 다른 분석이므로 합산하지 않습니다.</p>
<div class="live-grid">@foreach (['ordered'=>'주문금액','paid'=>'입금 완료 상품금액','cancelled'=>'취소금액','returned'=>'반품금액','net_sales'=>'순매출','refund_recorded'=>'환불 기록 (송금 아님)'] as $key=>$label)<section class="live-panel"><div class="live-stat-label">{{ $label }}</div><div class="live-amount">{{ number_format(array_sum(array_column($summary['periods'], $key))) }}원</div></section>@endforeach</div>
<section class="live-panel"><h2>재고 상태</h2>@forelse ($summary['stock'] as $state=>$quantity)<p>{{ ['available'=>'판매 가능','reserved'=>'예약','shipped'=>'출고','as'=>'A/S','defective'=>'불량','returned'=>'반품 대기','lost'=>'망실','disposed'=>'폐기'][$state] ?? $state }}: {{ $quantity }}개</p>@empty<p class="live-empty">아직 입고 내역이 없습니다.</p>@endforelse</section>
@if($section==='statistics')<section class="live-panel"><h2>기간별 실적</h2><div class="live-table-wrap"><table class="live-table"><thead><tr><th>기간</th><th>주문</th><th>입금 상품금액</th><th>취소</th><th>반품</th><th>순매출</th><th>잔여 판매 수량</th><th>출고 수량</th><th>직접 판매</th></tr></thead><tbody>@forelse($summary['periods'] as $period=>$values)<tr><th>{{ $period }}</th>@foreach(['ordered','paid','cancelled','returned','net_sales','quantity','shipped','direct_sales'] as $field)<td>{{ number_format($values[$field]) }}</td>@endforeach</tr>@empty<tr><td colspan="9">선택한 기간의 판매 내역이 없습니다.</td></tr>@endforelse</tbody></table></div><p class="live-help">입금일 기준은 확인 입금 시점에 귀속합니다. 주문상품별 환불을 임의 배분하지 않으므로 조건 필터에서는 금융 환불 총액을 제외합니다. 공동 참여자별 분석은 동일 판매의 다른 관점이므로 합산하지 않습니다.</p></section>@endif
@endif
@if ($fields !== [])
<section class="live-panel"><h2>{{ $editing ? '수정' : '새 항목 등록' }}</h2><form data-live-form method="POST" action="/seller/catalog/{{ $section }}{{ $editing ? '/'.$editing->id : '' }}">@csrf
<div class="live-grid">@foreach ($fields as $name => $field)
@php
    $value = $editing?->{$name};
    if (str_ends_with($name, '_percent')) { $r = $editing?->{str_replace('_percent','_rate',$name)}; $value = $r === null ? ($field['required'] ? '0' : '') : intdiv($r,100).'.'.str_pad((string)($r%100),2,'0',STR_PAD_LEFT); }
    if ($value instanceof \Carbon\CarbonInterface) $value = $value->copy()->setTimezone($tenant->timezone)->format('Y-m-d\TH:i');
@endphp
<div class="live-field"><label for="field-{{ $name }}">{{ $field['label'] }}</label>
@if ($field['type'] === 'checkbox')<input type="hidden" name="{{ $name }}" value="0"><input type="checkbox" id="field-{{ $name }}" name="{{ $name }}" value="1" @checked($value ?? $name === 'active')>
@elseif ($field['type'] === 'select')<select id="field-{{ $name }}" name="{{ $name }}" @required($field['required'])><option value="">선택해 주세요</option>@foreach ($choices[$name] ?? [] as $choice)<option value="{{ $choice->id }}" @selected((string)$value === (string)$choice->id)>{{ $choice->name ?? ($choice->bank.' · '.$choice->holder) }}{{ $field['choices'] === \Plugins\Yutiv\LiveCommerce\Enums\LiveEntity::Option ? ' · '.number_format($choice->price - $choice->discount).'원' : '' }}</option>@endforeach</select>
@elseif ($field['type'] === 'choice')<select id="field-{{ $name }}" name="{{ $name }}">@foreach($field['choices'] as $key=>$label)<option value="{{ $key }}" @selected($value===$key)>{{ $label }}</option>@endforeach</select>
@elseif ($field['type'] === 'textarea')<textarea id="field-{{ $name }}" name="{{ $name }}">{{ $value }}</textarea>
@elseif ($field['type'] === 'hosts')@forelse ($hosts ?? [] as $host)<label>{{ $host->name }} <input name="host_shares[{{ $host->id }}]" value="{{ isset($editing?->host_shares[$host->id]) ? intdiv($editing->host_shares[$host->id],100).'.'.str_pad((string)($editing->host_shares[$host->id]%100),2,'0',STR_PAD_LEFT) : '0' }}" inputmode="decimal">%</label>@empty<p class="live-help">먼저 쇼호스트를 등록해 주세요. 인센티브가 0%이면 참여자 없이 방송할 수 있습니다.</p>@endforelse
@else<input id="field-{{ $name }}" name="{{ $name }}" type="{{ $field['type'] }}" value="{{ $value ?? ($field['type']==='number' ? '0' : '') }}" @required($field['required']) @if($field['type']==='number') min="0" @endif>
@endif</div>@endforeach</div><button type="submit" class="live-button">{{ $editing ? '수정 저장' : '등록' }}</button><p class="live-status" data-live-message role="status"></p></form></section>
@endif
@if (in_array($section, ['receive','inventory','ship','returns'], true)) @include('teewide::seller.stock-forms') @endif
@if ($section === 'brand')<section class="live-panel"><h2>브랜드와 상담</h2><form data-live-form method="POST" action="/seller/tasks/brand">@csrf
<div class="live-field"><label for="brand-name">고객에게 표시할 브랜드 이름</label><input id="brand-name" name="name" value="{{ $tenant->name }}" maxlength="100" required></div>
@foreach(['kakao'=>'카카오','telegram'=>'텔레그램'] as $key=>$label)<div class="live-field"><label for="contact-{{ $key }}">{{ $label }} 상담 URL</label><input id="contact-{{ $key }}" name="{{ $key }}_url" type="url" value="{{ $tenant->contact_links[$key] ?? '' }}"><span class="live-help">승인된 HTTPS 주소만 사용할 수 있습니다.</span></div>@endforeach
<button class="live-button" type="submit">브랜드 저장</button><p class="live-status" data-live-message role="status"></p></form></section>@endif
@if ($section === 'returns') @include('teewide::seller.service-forms') @endif
@if ($section === 'staff') @include('teewide::seller.staff') @endif
@if ($section === 'labels') @include('teewide::seller.labels') @endif
@if($section==='brand' || ($section==='products' && $editing))
<section class="live-panel"><h2>{{ $section==='brand' ? '브랜드 로고' : '상품 이미지' }}</h2><form data-live-form data-live-upload method="POST" enctype="multipart/form-data" action="/seller/media/{{ $section==='brand' ? 'logo' : 'product' }}">@csrf
@if($section==='products')<input type="hidden" name="product_id" value="{{ $editing->id }}">@endif
<div class="live-field"><label for="media-image">이미지 파일 (PNG·JPEG·WebP, 최대 5MB)</label><input id="media-image" type="file" name="image" accept="image/png,image/jpeg,image/webp" required></div><button type="submit" class="live-button">이미지 저장</button><p data-live-message role="status"></p>
@if($section==='brand' ? $tenant->logo_path : $editing->image_path)<img data-image-preview src="/live-media/{{ $section==='brand' ? 'logo/logo' : 'product/'.$editing->id }}" alt="현재 이미지" style="max-width:200px">@endif
</form></section>@endif
@if ($section === 'incentives')<p class="live-help">예상 → 배송 완료 및 반품기간/구매확정 후 확정 → 담당자 승인·지급 기록. 은행 송금 기능은 없습니다. 음수 조정은 후속 정산에서 회수할 금액입니다.</p>@endif
@if($section==='incentives')<section class="live-panel"><h2>대상별 원장 합계</h2><div class="live-table-wrap"><table class="live-table"><thead><tr><th>대상</th><th>상태</th><th>금액</th></tr></thead><tbody>@forelse($balances as $balance)<tr><td>{{ $balance->display_name }}</td><td>{{ ['expected'=>'예상 (확정 전)','confirmed'=>'확정','payment'=>'승인·지급 기록','adjustment'=>'회수 부족·조정','recovery'=>'회수 확인 기록'][$balance->kind] ?? $balance->kind }}</td><td>{{ number_format($balance->amount) }}원</td></tr>@empty<tr><td colspan="3">아직 인센티브 원장 내역이 없습니다.</td></tr>@endforelse</tbody></table></div><a href="/seller/orders">주문별 검토·승인·회수로</a></section>@endif
@if ($section !== 'labels' && count($rows)>0)<section class="live-panel"><h2>등록 내역</h2><div class="live-table-wrap"><table class="live-table"><thead><tr><th>항목</th><th>현재 상태</th><th>작업</th></tr></thead><tbody>@foreach ($rows as $row)<tr><td>{{ $row->name ?? $row->bank ?? $row->uuid ?? $row->beneficiary ?? $row->action ?? '항목' }}@if(isset($row->amount)) · {{ number_format($row->amount) }}원 @endif</td><td>{{ $row->state ?? $row->payment_state ?? $row->role ?? (isset($row->active) ? ($row->active?'사용':'중지') : '') }}</td><td>
@if($fields !== [])<a href="/seller/{{ $section }}?edit={{ $row->id }}">수정</a>@endif
@if($section==='orders')<a href="/seller/order/{{ $row->uuid }}">주문·출고 보기</a>@endif
@if($section==='sale-links')<a href="/s/{{ $row->code }}" target="_blank" rel="noopener">판매 페이지</a><button type="button" data-copy="{{ $portalUrl }}/s/{{ $row->code }}" class="live-button live-button--quiet">링크 복사</button><div data-qr="{{ $portalUrl }}/s/{{ $row->code }}" style="width:100px"></div>@endif
@if(in_array($section,['inventory','receive','ship','returns'],true))<a href="/seller/inventory?unit={{ $row->uuid }}">물품 이력·정정</a><a href="/seller/labels?unit={{ $row->uuid }}">단건 라벨</a>@endif
@if($section==='options')<div data-qr="P:{{ $row->qr_code }}" style="width:100px"></div>@endif
</td></tr>@endforeach</tbody></table></div>@if(method_exists($rows,'links')){{ $rows->links() }}@endif</section>
@elseif ($section !== 'dashboard' && $section !== 'statistics' && $section !== 'brand' && $section !== 'labels')<p class="live-empty">아직 등록된 내역이 없습니다. 위에서 첫 항목을 등록해 주세요.</p>@endif
@isset($unitDetail) @include('teewide::seller.unit-history') @endisset
@endsection

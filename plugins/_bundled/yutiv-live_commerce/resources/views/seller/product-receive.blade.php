<section class="live-panel"><h2>1. 상품 기준 선택</h2>
<form method="GET"><label>상품명·상품코드 검색<input name="search" value="{{ request('search') }}" maxlength="200"></label><button class="live-button" type="submit">검색</button></form>
<form method="GET"><label>기존·유사 상품 선택<select name="product_id"><option value="">완전 신규 상품</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected($source?->id===$product->id)>{{ $product->code }} · {{ $product->name }}</option>@endforeach</select></label><button type="submit" class="live-button">상품 선택</button></form>
<p class="live-help">검색은 최대 50건입니다. 상품 초안만 저장하려면 <a href="/seller/products">상품 목록·초안 등록</a>을 사용하세요. 아래 저장은 실제 입고 확정입니다.</p></section>
<form data-live-form data-receipt-form action="/seller/receipt" method="POST">@csrf
<input type="hidden" name="operation_key" value="{{ bin2hex(random_bytes(16)) }}">
@if($source)<input type="hidden" name="product_id" value="{{ $source->id }}">@endif
<section class="live-panel"><label>처리 방식<select name="mode" data-receipt-mode>
@if($source)<option value="existing">기존 상품 추가 입고 (상품코드·기존 옵션 변경 없음)</option>@if($role->allows('catalog'))<option value="copy">선택 상품을 복사하여 새 상품 생성</option>@endif
@else<option value="new">완전 신규 상품</option>@endif</select></label>
@if(!$source && !$role->allows('catalog'))<p role="alert">상품을 먼저 선택하세요. 현재 역할은 기존 상품 추가 입고만 가능합니다.</p>@endif
<div data-receipt-basics @if($source) hidden @endif><h2>2. 새 상품 기본 정보</h2>
<p>복사본의 이름·코드를 입력합니다. 기존 상품의 코드·재고·QR·판매 및 입고 기록은 변경하거나 복사하지 않습니다. 복사한 코드는 업체 내 중복이므로 반드시 변경하세요.</p>
<label>새 상품명<input name="name" value="{{ $source?->name }}" maxlength="200"></label><label>새 상품코드<input name="code" value="{{ $source?->code }}" maxlength="100"></label>
<label>설명<textarea name="description">{{ $source?->description }}</textarea></label>
<label>공개 노출<select name="published"><option value="0">숨김</option><option value="1" @selected($source?->published)>공개</option></select></label>
<label>판매 가능<select name="sellable"><option value="0">판매 중지</option><option value="1" @selected($source?->sellable)>판매 가능</option></select></label>
<p>상품 이미지는 입고 완료 후 상품 수정 화면에서 기존 업로드 기능으로 등록하세요.</p></div></section>
<section class="live-panel"><h2>3. 옵션과 수량</h2>
<div data-combination-controls @if($source) hidden @endif class="live-grid"><label>옵션명 1<input data-axis-name value="색상"></label><label>값 1 (쉼표 구분)<input data-axis-values placeholder="검정, 흰색"></label><label>옵션명 2<input data-axis-name value="사이즈"></label><label>값 2 (쉼표 구분)<input data-axis-values placeholder="S, M, L"></label><button class="live-button" type="button" data-combinations>조합 표 생성 (입력 중인 표 교체)</button></div>
<p>옵션이 없으면 ‘기본’ 한 행을 사용합니다. 현재 판매 가능은 available만 포함하며 예약 재고는 별도 표시합니다. 예상 재고 = 판매 가능 + 이번 입고 (다른 작업에 따라 실제 확정 수량과 달라질 수 있음).</p>
<div class="live-table-wrap"><table class="live-table"><thead><tr><th>제외</th><th>옵션</th><th>판매가격</th><th>할인</th><th>판매 가능 / 예약</th><th>이번 입고</th><th>예상 판매 가능</th></tr></thead><tbody data-receipt-rows></tbody></table></div>
@php($receiptOptions = $options->map(fn($o)=>['option_id'=>$o->id,'name'=>$o->name,'price'=>$o->price,'discount'=>$o->discount,'available'=>$o->available,'reserved'=>$o->reserved])->values())
<script type="application/json" data-receipt-options>@json($receiptOptions)</script>
<p data-receipt-total role="status"></p></section>
<section class="live-panel"><h2>4. 입고 확정</h2><p>실제 입고일시는 확정 시 서버 시각으로 기록합니다 ({{ $tenant->timezone }}). 과거 일자 소급 입력은 지원하지 않습니다. 한 번에 총 1~200개, 최대 50옵션입니다. 확정 입고 취소는 이 화면에서 지원하지 않으며 기존 물품 이력·사유 있는 재고 조정 절차를 사용하세요. 예약·출고 물품은 입고 취소할 수 없습니다.</p>
<label>입고 위치<input name="location" required maxlength="100"></label><label>입고 메모<textarea name="memo" maxlength="2000"></textarea></label>
<button class="live-button" type="submit" @disabled(!$source && !$role->allows('catalog'))>상품·입고 확정</button><p class="live-status" data-live-message role="status"></p></section></form>
@if($source && $role->allows('catalog'))<section class="live-panel"><h2>기존 상품에 옵션 추가</h2><p>거래 이력이 있는 옵션은 유지합니다. 동일 옵션은 재입고 표에서 선택하세요. 새 옵션 저장 후 ‘상품 선택’을 눌러 입고 표를 갱신하세요.</p><form data-live-form action="/seller/catalog/options" method="POST">@csrf<input name="product_id" type="hidden" value="{{ $source->id }}"><label>옵션명<input name="name" required maxlength="200"></label><label>가격<input name="price" type="number" min="0" step="1" required></label><label>할인<input name="discount" type="number" min="0" step="1" value="0" required></label><input name="active" type="hidden" value="1"><button class="live-button" type="submit">새 옵션 저장</button><p data-live-message role="status"></p></form></section>@endif
@if($source && $role->allows('catalog'))<section class="live-panel"><h2>기존 옵션 가격·판매 설정</h2><p>기존 옵션 ID와 QR을 유지합니다. 이미 생성된 주문의 가격 스냅샷은 바뀌지 않습니다. 저장 후 입고 표를 새로고침하세요.</p>
@foreach($options as $option)<details><summary>{{ $option->name }} · {{ number_format($option->price) }}원 / 할인 {{ number_format($option->discount) }}원</summary><form data-live-form action="/seller/catalog/options/{{ $option->id }}" method="POST">@csrf<input type="hidden" name="product_id" value="{{ $source->id }}"><input type="hidden" name="name" value="{{ $option->name }}"><div class="live-grid"><label>가격<input type="number" name="price" min="0" step="1" value="{{ $option->price }}" required></label><label>할인<input type="number" name="discount" min="0" step="1" value="{{ $option->discount }}" required></label><label>판매 설정<select name="active"><option value="1" @selected($option->active)>사용</option><option value="0" @selected(!$option->active)>중지</option></select></label></div><button class="live-button" type="submit">이 옵션 설정 저장</button><p data-live-message role="status"></p></form></details>@endforeach</section>@endif

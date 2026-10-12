<section class="live-panel"><h2>A/S 입고·출고</h2><p class="live-help">판매 취소·반품과 구분합니다. A/S 중에는 판매 가능 재고에 포함하지 않으며 주문 수량과 인센티브를 변경하지 않습니다. A/S 출고는 입고 전 상태로 복귀합니다.</p>
@foreach(['as-receive'=>'A/S 입고 확인','as-dispatch'=>'A/S 완료 출고 확인'] as $action=>$label)
<form data-live-form method="POST" action="/seller/tasks/{{ $action }}">@csrf<input type="hidden" name="operation_key" value="{{ Str::uuid() }}"><h3>{{ $label }}</h3>
<label>개별 물품 QR<input name="qr" required placeholder="U:물품 QR"></label><label>확인한 위치<input name="location" required maxlength="100"></label><label>작업 근거<textarea name="reason" required maxlength="1000"></textarea></label><button type="submit" class="live-button">{{ $label }}</button><p data-live-message role="status"></p></form>
@endforeach</section>

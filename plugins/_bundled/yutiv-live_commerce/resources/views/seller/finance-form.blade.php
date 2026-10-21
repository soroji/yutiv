@php
$key=bin2hex(random_bytes(16));
$today=now($tenant->timezone)->format('Y-m-d');
@endphp
<form data-live-form data-stock-count data-finance-form action="/seller/accounting/actions/{{ $action }}" method="POST" class="finance-form">
@csrf<input type="hidden" name="operation_key" value="{{ $key }}">
@foreach($fields as $name=>$field)
@php
$label=$field[0];$type=$field[1]??'text';$value=$field[2]??'';$choices=$field[3]??[];$optional=$field[4]??false;
@endphp
@if($type==='hidden')<input type="hidden" name="{{ $name }}" value="{{ $value }}">
@else<label>{{ $label }}
@if($type==='select')<select name="{{ $name }}" @required(!$optional)>@if($optional)<option value="">선택 안 함</option>@endif @foreach($choices as $optionValue=>$optionLabel)<option value="{{ $optionValue }}" @selected((string)$value===(string)$optionValue)>{{ $optionLabel }}</option>@endforeach</select>
@elseif($type==='textarea')<textarea name="{{ $name }}" maxlength="2000" @required(!$optional)>{{ $value }}</textarea>
@else<input name="{{ $name }}" type="{{ $type }}" value="{{ $value==='@today'?$today:$value }}" @required(!$optional) @if($type==='number') min="{{ $name==='labor_ratio_bps'?0:1 }}" step="1" @endif @if(in_array($name,['amount_original','amount_out','amount_in','fee_original','exchange_rate'])) inputmode="decimal" @endif>
@endif</label>@endif
@endforeach
<p class="finance-conversion" data-finance-conversion role="status"></p><button class="live-button" type="submit">{{ $submit??'기록 저장' }}</button><p data-live-message role="status"></p>
</form>

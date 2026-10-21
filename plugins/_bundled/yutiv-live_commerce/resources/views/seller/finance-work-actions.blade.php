<section class="finance-action-area {{ $section==='expenses'?'finance-expense-work':'' }}" aria-label="업무 작업">
@if($section==='expenses')<div class="finance-task-groups">
@foreach(['지급·회수'=>['payment','payment-recovery'],'입고 연결'=>['expense-link-receipt'],'환율·정정'=>['rate-confirm','expense-void'],'분류·정기 규칙'=>['category','recurring']] as $group=>$actions)
@php($allowed=array_filter($actions,fn($action)=>isset($forms[$action]) && $role->allows($forms[$action][1])))
@if($allowed)<details class="finance-task-group"><summary>{{ $group }}<span>{{ count($allowed) }}개 작업</span></summary><div class="finance-task-items">@foreach($allowed as $action)<button type="button" class="live-button live-button--quiet" data-finance-open="finance-{{ $action }}">{{ $forms[$action][0] }}</button>@endforeach</div></details>@endif
@endforeach</div>
@else<div class="finance-actions">@foreach($forms as $action=>[$label,$ability,$fields])@if($action!=='expense' && $role->allows($ability))<button type="button" class="live-button live-button--quiet" data-finance-open="finance-{{ $action }}">{{ $label }}</button>@endif @endforeach</div>@endif
@foreach($forms as $action=>[$label,$ability,$fields])@if($role->allows($ability))<dialog id="finance-{{ $action }}" class="finance-dialog" aria-labelledby="finance-title-{{ $action }}"><header><h2 id="finance-title-{{ $action }}">{{ $label }}</h2><button type="button" data-finance-close>닫기</button></header>@include('teewide::seller.finance-form',['action'=>$action,'fields'=>$fields])</dialog>@endif @endforeach
</section>

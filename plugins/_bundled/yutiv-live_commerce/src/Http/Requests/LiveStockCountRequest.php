<?php
namespace Plugins\Yutiv\LiveCommerce\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;use Illuminate\Validation\Rule;use Plugins\Yutiv\LiveCommerce\Support\LiveContext;
class LiveStockCountRequest extends FormRequest {
 public function authorize():bool {LiveContext::authorize($this->route('action')==='confirm'?'inventory.count-confirm':'inventory');return true;}
 public function rules():array {
  if($this->isMethod('GET'))return ['product_id'=>['nullable','integer','min:1'],'option_id'=>['nullable','integer','min:1'],'count'=>['nullable','integer','min:1'],'unit'=>['nullable','uuid'],'list'=>['nullable','string','max:4000'],'location'=>['nullable','string','max:100'],'page'=>['nullable','integer','min:1']];
  $common=['list'=>['nullable','string','max:4000'],'operation_key'=>['required','string','max:64','regex:/^[a-zA-Z0-9-]+$/']];
  if($this->route('action')==='confirm')return $common+['id'=>['required','integer','min:1'],'revision'=>['required','integer','min:1']];
  return $common+['id'=>['nullable','integer','min:1'],'revision'=>['required_with:id','integer','min:1'],'option_id'=>['required','integer','min:1'],'location'=>['required','string','max:100'],'counted_quantity'=>['required','integer','min:0','max:1000000'],'target_state'=>['required',Rule::in(['lost','disposed','available'])],'reason'=>['required','string','max:1000'],'qrs'=>['nullable','array','max:200'],'qrs.*'=>['required','uuid','distinct'],'refresh'=>['nullable','boolean']];
 }
}

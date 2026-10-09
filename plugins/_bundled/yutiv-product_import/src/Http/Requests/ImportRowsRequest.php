<?php

namespace Plugins\Yutiv\ProductImport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductTaxStatus;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreProductRequest;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Plugins\Yutiv\ProductImport\Models\ImportIdentity;
use Plugins\Yutiv\ProductImport\Support\ImageFetcher;
use Plugins\Yutiv\ProductImport\Support\Workbook;

/** Spreadsheet validation adapter; all normal product rules and after validators are reused. */
class ImportRowsRequest extends FormRequest
{
    public function validateWorkbook(array $book, ?int $ownRow = null): array
    {
        $errors = $book['formula_errors'] ?? [];
        $rows = [];
        $codes = [];
        $options = [];
        $add = function ($sheet, $row, $column, $message, $fix = '양식 안내에 맞게 수정한 후 다시 업로드하세요.') use (&$errors) {
            $errors[] = compact('sheet', 'row', 'column', 'message', 'fix');
        };
        foreach ($book['상품'] as $rn => $p) {
            $code = $p[0] ?? '';
            $key = 'code:'.mb_strtolower($code);
            if ($code === '' || ! preg_match('/^[A-Za-z0-9_-]{1,50}$/', $code)) {
                $add('상품', $rn, '관리코드', '영문·숫자·밑줄·하이픈 1~50자로 입력하세요.');
            }
            if (isset($codes[$key])) {
                $add('상품', $rn, '관리코드', '파일 안에서 관리코드가 중복됩니다.');
                $add('상품', $codes[$key], '관리코드', '파일 안에서 관리코드가 중복됩니다.');
            }
            $codes[$key] = $rn;
            $canonical = mb_strtolower($code);
            if (ImportIdentity::where('management_code', $canonical)->when($ownRow, fn ($q) => $q->where('row_id', '!=', $ownRow))->exists()
                || Product::withTrashed()->whereRaw('LOWER(product_code) = ?', [$canonical])->orWhereRaw('LOWER(sales_product_code) = ?', [$canonical])->orWhereRaw('LOWER(sku) = ?', [$canonical])->exists()) {
                $add('상품', $rn, '관리코드', '기존 상품 또는 확정 작업이 사용하는 코드입니다.', '새 관리코드를 사용하세요. 기존 상품은 덮어쓰지 않습니다.');
            }
        }
        foreach ($book['옵션'] as $rn => $o) {
            $key = 'code:'.mb_strtolower($o[0] ?? '');
            if (! isset($codes[$key])) {
                $add('옵션', $rn, '상품 관리코드', '해당 상품 행이 없습니다.');

                continue;
            }
            $options[$key][] = [$rn, $o];
        }
        foreach ($book['상품'] as $rn => $p) {
            $code = $p[0] ?? '';
            $key = 'code:'.mb_strtolower($code);
            $sourceOptions = $options[$key] ?? [];
            $cats = [];
            foreach (explode('|', $p[2] ?? '') as $cat) {
                if (! ctype_digit($cat) || ! ($c = Category::find((int) $cat))) {
                    $add('상품', $rn, '카테고리 코드', '존재하는 카테고리 코드를 입력하세요.');

                    continue;
                }
                $seen = [];
                $active = true;
                while ($c) {
                    if (isset($seen[$c->id]) || ! $c->is_active) {
                        $active = false;
                        break;
                    }$seen[$c->id] = true;
                    $c = $c->parent_id ? Category::find($c->parent_id) : null;
                }
                if (! $active) {
                    $add('상품', $rn, '카테고리 코드', '해당 카테고리 또는 상위 카테고리가 비활성 상태입니다.');
                }
                $cats[] = (int) $cat;
            }
            if (count($cats) !== count(array_unique($cats))) {
                $add('상품', $rn, '카테고리 코드', '카테고리 코드가 중복됩니다.');
            }
            $locale = config('app.locale', 'ko');
            $price = $p[3] ?? '';
            $stock = $p[4] ?? '';
            $opts = [];
            $groups = [];
            $combos = [];
            $ids = [];
            $groupKeys = null;
            foreach ($sourceOptions as [$or,$o]) {
                $values = [];
                $names = [];
                for ($i = 2; $i <= 6; $i += 2) {
                    $name = $o[$i] ?? '';
                    $value = $o[$i + 1] ?? '';
                    if (($name === '') !== ($value === '')) {
                        $add('옵션', $or, Workbook::OPTION[$i], '옵션명과 옵션값을 함께 입력하세요.');
                    }
                    if ($name !== '') {
                        if (in_array($name, $names, true)) {
                            $add('옵션', $or, Workbook::OPTION[$i], '옵션 그룹 이름이 중복됩니다.');
                        }$names[] = $name;
                        $values[] = ['key' => [$locale => $name], 'value' => [$locale => $value]];
                        $groups[$name][$value] = [$locale => $value];
                    }
                }
                if (! $values) {
                    $add('옵션', $or, '옵션명1', '옵션 상품은 최소 한 쌍의 옵션명·값이 필요합니다.');
                }
                if ($groupKeys !== null && $groupKeys !== $names) {
                    $add('옵션', $or, '옵션명1', '같은 상품은 옵션 그룹 이름과 순서를 일치시키세요.');
                }
                $groupKeys = $names;
                $signature = json_encode($values, JSON_UNESCAPED_UNICODE);
                $id = $o[1] ?? '';
                if (! preg_match('/^[A-Za-z0-9_-]{1,50}$/', $id) || isset($ids[mb_strtolower($id)])) {
                    $add('옵션', $or, '옵션 식별자', '상품 안에서 고유한 영문·숫자 식별자를 입력하세요.');
                }
                if (isset($combos[$signature])) {
                    $add('옵션', $or, '옵션값1', '같은 옵션 조합이 중복됩니다.');
                }
                $ids[mb_strtolower($id)] = true;
                $combos[$signature] = true;
                $adjust = $o[8] ?? '0';
                if (! preg_match('/^-?\d+(?:\.\d+)?$/', $adjust)) {
                    $add('옵션', $or, '추가금액', '숫자로 입력하세요.');
                }
                if (! $opts && is_numeric($adjust) && (float) $adjust !== 0.0) {
                    $add('옵션', $or, '추가금액', '첫 옵션은 기본 옵션이므로 추가금액을 0으로 입력하세요.');
                }
                $opts[] = ['option_code' => $id, 'option_name' => [$locale => implode(' / ', array_map(fn ($v) => $v['value'][$locale], $values))],
                    'option_values' => $values, 'list_price' => $price, 'selling_price' => $price, 'price_adjustment' => $adjust,
                    'stock_quantity' => $o[9] ?? '', 'is_default' => count($opts) === 0, 'is_active' => true];
                if (is_numeric($price) && is_numeric($adjust) && (float) $price + (float) $adjust < 0) {
                    $add('옵션', $or, '추가금액', '판매가와 추가금액의 합계는 음수일 수 없습니다.');
                }
            }
            if (count($opts) > 100) {
                $add('상품', $rn, '관리코드', '상품당 옵션은 100개 이하로 입력하세요.');
            }
            if ($opts && ctype_digit((string) $stock) && count(array_filter($opts, fn ($o) => ! ctype_digit((string) $o['stock_quantity']))) === 0
                && (int) $stock !== array_sum(array_column($opts, 'stock_quantity'))) {
                $add('상품', $rn, '재고', '상품 재고가 옵션 재고 합계와 다릅니다.', '옵션 시트 재고 합계를 상품 재고에 입력하세요.');
            }
            $payload = ['name' => [$locale => $p[1] ?? ''], 'product_code' => 'preview-'.substr(hash('sha256', $code), 0, 32), 'sales_product_code' => $code, 'category_ids' => $cats,
                'primary_category_id' => $cats[0] ?? null, 'list_price' => $price, 'selling_price' => $price, 'stock_quantity' => $stock,
                'display_status' => ProductDisplayStatus::HIDDEN->value, 'sales_status' => ProductSalesStatus::SUSPENDED->value, 'tax_status' => ProductTaxStatus::TAXABLE->value,
                'description' => [$locale => $p[5] ?? ''], 'description_mode' => 'html', 'has_options' => (bool) $sourceOptions, 'options' => $opts,
                'option_groups' => array_map(fn ($name, $values) => ['name' => [$locale => (string) $name], 'values' => array_values($values)], array_keys($groups), array_values($groups))];
            // Reuse exact FormRequest rules, messages, attributes and cross-field callbacks.
            $req = StoreProductRequest::create('/', 'POST', $payload);
            $req->setContainer(app())->setRedirector(app('redirect'));
            $rules = $req->rules();
            // Enforce the actual DECIMAL(15,2)/signed INT storage capacity before queueing.
            foreach (['list_price', 'selling_price', 'options.*.list_price', 'options.*.selling_price', 'options.*.price_adjustment'] as $field) {
                $base = $rules[$field] ?? [];
                $rules[$field] = array_merge(is_array($base) ? $base : explode('|', $base), ['max:9999999999999.99']);
            }
            $rules['options.*.price_adjustment'][] = 'min:-9999999999999.99';
            foreach (['stock_quantity', 'options.*.stock_quantity'] as $field) {
                $base = $rules[$field] ?? [];
                $rules[$field] = array_merge(is_array($base) ? $base : explode('|', $base), ['max:2147483647']);
            }
            $v = Validator::make($payload, $rules, $req->messages(), $req->attributes());
            $req->setValidator($v);
            $req->withValidator($v);
            if ($v->fails()) {
                foreach ($v->errors()->messages() as $field => $messages) {
                    $or = $rn;
                    $sheet = '상품';
                    $column = match (explode('.', $field)[0]) {
                        'name' => '상품명','stock_quantity' => '재고','selling_price','list_price' => '판매가','description' => '상세설명','category_ids','primary_category_id' => '카테고리 코드',default => $field
                    };
                    if (preg_match('/^options\.(\d+)\.(.+)/', $field, $m) && isset($sourceOptions[(int) $m[1]])) {
                        $sheet = '옵션';
                        $or = $sourceOptions[(int) $m[1]][0];
                        $column = match ($m[2]) {
                            'stock_quantity' => '재고','price_adjustment' => '추가금액','option_code' => '옵션 식별자',default => '옵션명1'
                        };
                    }
                    foreach ($messages as $message) {
                        $add($sheet, $or, $column, $message);
                    }
                }
            }
            $urls = array_values(array_filter(array_merge([$p[6] ?? ''], explode('|', $p[7] ?? '')), fn ($u) => $u !== ''));
            if (count($urls) > 5) {
                $add('상품', $rn, '추가 이미지 URL', '이미지는 대표 포함 5개 이하로 입력하세요.');
            }
            foreach ($urls as $index => $url) {
                try {
                    app(ImageFetcher::class)->inspect($url);
                } catch (ValidationException $e) {
                    $add('상품', $rn, $index === 0 && ($p[6] ?? '') !== '' ? '대표 이미지 URL' : '추가 이미지 URL', implode(' ', array_merge(...array_values($e->errors()))));
                }
            }
            $rows[] = ['source_row' => $rn, 'management_code' => $code, 'source' => ['product' => $p, 'options' => $sourceOptions], 'payload' => $payload, 'image_urls' => $urls];
        }
        if (! $rows) {
            $add('상품', 2, '관리코드', '등록할 상품이 없습니다.');
        }

        return ['rows' => $rows, 'errors' => $errors];
    }
}

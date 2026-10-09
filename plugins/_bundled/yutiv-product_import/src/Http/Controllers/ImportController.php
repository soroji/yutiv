<?php

namespace Plugins\Yutiv\ProductImport\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Plugins\Yutiv\ProductImport\Http\Requests\ImportUploadRequest;
use Plugins\Yutiv\ProductImport\Models\ImportRun;
use Plugins\Yutiv\ProductImport\Services\ImportService;
use Plugins\Yutiv\ProductImport\Support\Workbook;

class ImportController extends AdminBaseController
{
    public function __construct(private ImportService $imports, private Workbook $xlsx) {}

    private function run(Request $request, string $id): ImportRun
    {
        return ImportRun::whereKey($id)->when(! $request->user()->isSuperAdmin(), fn ($q) => $q->where('user_id', $request->user()->id))->firstOrFail();
    }

    private function download(array $sheets, string $filename)
    {
        return response($this->xlsx->write($sheets), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function template()
    {
        $categories = [['카테고리 코드', '상위 경로 포함 이름', '사용 가능']];
        $all = Category::all()->keyBy('id');
        foreach ($all as $cat) {
            $names = [];
            $current = $cat;
            $seen = [];
            $active = true;
            while ($current) {
                if (isset($seen[$current->id])) {
                    $active = false;
                    break;
                }$seen[$current->id] = true;
                $names[] = ($current->name[app()->getLocale()] ?? $current->name['ko'] ?? array_values($current->name)[0] ?? '');
                $active = $active && $current->is_active;
                $current = $all[$current->parent_id] ?? null;
            }
            $categories[] = [(string) $cat->id, implode(' > ', array_reverse($names)), $active ? '가능' : '불가'];
        }

        return $this->download(['입력 안내' => [
            ['항목', '입력 방법'], ['정책', '신규 상품만 비공개·판매중지·과세 상태로 등록합니다. 정가=판매가입니다.'],
            ['순서', '상품·옵션 시트 입력 → 업로드 검증 → 오류 수정 → 등록 확정 → 결과 확인'],
            ['관리코드', '영문·숫자·밑줄·하이픈 1~50자. 문자열로 입력해 앞자리 0을 보존하세요. 기존 상품/예약 코드 재사용 불가.'],
            ['카테고리 코드', '카테고리 안내의 코드(ID)를 문자열로 입력합니다. 여러 개는 |로 구분(최대 5개). 첫 코드가 대표입니다.'],
            ['금액', '현재 쇼핑몰 기본통화 기준. KRW는 정수, 다른 통화는 설정된 소수 자릿수. 판매가는 양수입니다.'],
            ['재고', '0 이상의 정수. 옵션 상품의 상품 재고는 옵션 재고 합계와 같아야 합니다.'],
            ['옵션', '조합 1개당 1행. 옵션명·값 최대 3쌍, 같은 상품은 그룹 이름/순서를 일치시킵니다. 첫 옵션이 기본 옵션이며 추가금액은 0. 상품당 최대 100개. 옵션 없으면 옵션 시트를 비워둡니다.'],
            ['추가금액', '상품 판매가에 더하는 금액입니다. 기존 통화 소수 규칙 적용.'],
            ['이미지', '대표 URL 1개 + 추가 URL을 |로 구분, 총 5개 이하. HTTPS 공개 주소만. 확정 후 내부 저장소로 가져옵니다.'],
            ['파일 제한', 'xlsx 10MB, 상품 500행, 옵션 2000행, 내부 파일 1000개, 압축 해제 전체 50MB/개별 16MB. 수식·매크로·외부 연결 불가.'],
            ['이미지 제한', '파일당 10MB, 각 변 10000px, 총 2000만 픽셀, JPEG/PNG/GIF/WebP. 요청당 연결 5초/전체 20초, 리다이렉트 최대 3회.'],
            ['상품 예시(등록되지 않음)', '000123 / 예시 상품 / 카테고리 안내의 실제 코드 / 12000 / 5'],
            ['옵션 예시(등록되지 않음)', '000123 / 0001 / 색상 / 베이지 / 크기 / M / (빈칸) / (빈칸) / 0 / 5'],
            ['상세설명', '기존 상품 등록과 동일하게 HTML을 정제합니다. 이미지는 승인된 URL로 준비하세요.'],
        ], '상품' => [Workbook::PRODUCT], '옵션' => [Workbook::OPTION], '카테고리 안내' => $categories], 'yutiv-products-template.xlsx');
    }

    public function preview(ImportUploadRequest $request)
    {
        $run = $this->imports->preview($request->file('file')->getRealPath(), $request->file('file')->getClientOriginalName(), $request->user());

        return ResponseHelper::success(data: $this->imports->summary($run));
    }

    public function index(Request $request)
    {
        $runs = ImportRun::query()->when(! $request->user()->isSuperAdmin(), fn ($q) => $q->where('user_id', $request->user()->id))->latest()->limit(30)->get(['id', 'filename', 'status', 'created_at']);

        return ResponseHelper::success(data: ['items' => $runs]);
    }

    public function show(Request $request, string $id)
    {
        return ResponseHelper::success(data: $this->imports->summary($this->run($request, $id)));
    }

    public function confirm(Request $request, string $id)
    {
        $run = $this->run($request, $id);
        $this->imports->confirm($run);

        return ResponseHelper::success(data: $this->imports->summary($run->fresh()));
    }

    public function retry(Request $request, string $id)
    {
        $run = $this->run($request, $id);
        $this->imports->retry($run);

        return ResponseHelper::success(data: $this->imports->summary($run->fresh()));
    }

    public function result(Request $request, string $id)
    {
        $run = $this->run($request, $id);
        $products = [['원본 상품 행', '관리코드', '상품명', '상태', '상품코드', '실패 내용']];
        $options = [['원본 옵션 행', '상품 관리코드', '옵션 식별자', '상태', '실패 내용']];
        foreach ($run->rows()->orderBy('source_row')->get() as $row) {
            $products[] = [(string) $row->source_row, $row->management_code, $row->source['product'][1] ?? '', $row->status->label(), $row->product_code ?? '', $row->error ?? ''];
            foreach ($row->source['options'] as [$rn,$o]) {
                $options[] = [(string) $rn, $o[0] ?? '', $o[1] ?? '', $row->status->label(), $row->error ?? ''];
            }
        }
        $errors = [['시트', '원본 행', '열 이름', '오류', '수정 방법']];
        foreach ($run->errors ?? [] as $e) {
            $errors[] = [$e['sheet'], (string) $e['row'], $e['column'], $e['message'], $e['fix']];
        }

        return $this->download(['상품 결과' => $products, '옵션 결과' => $options, '검증 오류' => $errors], 'yutiv-import-result.xlsx');
    }
}

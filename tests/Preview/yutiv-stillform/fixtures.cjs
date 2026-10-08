/**
 * 프리뷰 픽스처 — 이커머스 공개 API 응답 형태를 그대로 흉내 낸 데이터.
 *
 * 필드 구성은 모듈의 실제 리소스 계약을 따른다:
 *   - 상품: modules/_bundled/sirsoft-ecommerce/src/Http/Resources/ProductListResource.php
 *   - 카테고리: .../PublicCategoryResource.php
 * 새 필드를 지어내지 않는다. 값만 예시다.
 *
 * 이미지: 외부 요청이 0건이어야 하고 깨진 이미지 아이콘도 0건이어야 하므로
 * **로컬에서 생성한 SVG data URI** 를 쓴다. 네트워크를 타지 않고 항상 렌더된다.
 */

/** 상품 이미지 자리표시 — 브랜드/상품명을 얹은 SVG data URI. */
function placeholder(label, sub, hue, w = 600, h = 800) {
    const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}">
<defs><linearGradient id="g" x1="0" y1="0" x2="0" y2="1">
<stop offset="0%" stop-color="hsl(${hue},18%,92%)"/><stop offset="100%" stop-color="hsl(${hue},14%,82%)"/>
</linearGradient></defs>
<rect width="${w}" height="${h}" fill="url(#g)"/>
<text x="50%" y="46%" text-anchor="middle" font-family="system-ui,sans-serif" font-size="${Math.round(w / 22)}" letter-spacing="4" fill="hsl(${hue},12%,38%)">${esc(sub)}</text>
<text x="50%" y="54%" text-anchor="middle" font-family="system-ui,sans-serif" font-size="${Math.round(w / 30)}" fill="hsl(${hue},10%,50%)">${esc(label)}</text>
</svg>`;
    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg.replace(/\n/g, ''));
}

const KRW = (n) => '₩' + n.toLocaleString('ko-KR');
const USD = (n) => '$' + Math.round(n / 1350).toLocaleString('en-US');

/** 로케일별 상품명 — 이커머스는 name_localized 로 내려준다(템플릿이 번역하지 않는다). */
const PRODUCT_NAMES = {
    ko: ['수분 진정 앰플 30ml', '글로우 쿠션 파운데이션', '오버사이즈 울 코트', '카본 드라이버 헤드커버',
        '세라믹 리드 디퓨저', '실크 블렌드 셔츠', '경량 골프 윈드재킷', '리페어 나이트 크림',
        '데일리 선 스틱', '라이트 캐시미어 니트', '투어 퍼포먼스 폴로', '리넨 쿠션 커버'],
    en: ['Hydrating Calm Ampoule 30ml', 'Glow Cushion Foundation', 'Oversized Wool Coat', 'Carbon Driver Headcover',
        'Ceramic Reed Diffuser', 'Silk Blend Shirt', 'Lightweight Golf Windbreaker', 'Repair Night Cream',
        'Daily Sun Stick', 'Light Cashmere Knit', 'Tour Performance Polo', 'Linen Cushion Cover'],
    ja: ['保湿鎮静アンプル 30ml', 'グロークッションファンデ', 'オーバーサイズウールコート', 'カーボンドライバーカバー',
        'セラミックリードディフューザー', 'シルクブレンドシャツ', '軽量ゴルフウインドブレーカー', 'リペアナイトクリーム',
        'デイリーサンスティック', 'ライトカシミヤニット', 'ツアーパフォーマンスポロ', 'リネンクッションカバー'],
    'zh-CN': ['保湿舒缓安瓶 30ml', '光泽气垫粉底', '廓形羊毛大衣', '碳素球杆头套',
        '陶瓷无火香薰', '真丝混纺衬衫', '轻量高尔夫风衣', '修护晚霜',
        '日常防晒棒', '轻盈羊绒针织衫', '巡回性能POLO衫', '亚麻抱枕套'],
};

const CATEGORY_NAMES = {
    ko: ['스킨케어', '메이크업', '아우터', '셔츠', '골프', '리빙'],
    en: ['Skincare', 'Makeup', 'Outerwear', 'Shirts', 'Golf', 'Living'],
    ja: ['スキンケア', 'メイクアップ', 'アウター', 'シャツ', 'ゴルフ', 'リビング'],
    'zh-CN': ['护肤', '彩妆', '外套', '衬衫', '高尔夫', '家居'],
};

const CATEGORY_OF = [0, 1, 2, 4, 5, 3, 4, 0, 0, 3, 4, 5];
const BRANDS = ['AUREN', 'AUREN', 'MAISON N', 'FAIRWAY', 'OBJET', 'MAISON N', 'FAIRWAY', 'AUREN', 'AUREN', 'MAISON N', 'FAIRWAY', 'OBJET'];
const CODES = ['YTVBEA0001', 'YTVBEA0002', 'YTVFAS0003', 'YTVGLF0004', 'YTVLIF0005', 'YTVFAS0006',
    'YTVGLF0007', 'YTVBEA0008', 'YTVBEA0009', 'YTVFAS0010', 'YTVGLF0011', 'YTVLIF0012'];
const PRICES = [
    [38000, 45000], [42000, 42000], [289000, 390000], [78000, 78000],
    [54000, 68000], [128000, 128000], [219000, 260000], [62000, 62000],
    [24000, 28000], [178000, 178000], [98000, 128000], [36000, 36000],
];
// 4번 상품(카본 드라이버 헤드커버)만 품절 — 품절 표현을 캡처에 담기 위한 의도적 배치
const STOCK = [30, 12, 4, 0, 18, 9, 6, 21, 44, 7, 15, 26];
const HUES = [24, 340, 210, 150, 40, 200, 165, 20, 45, 15, 140, 30];

const SALES_STATUS_LABEL = {
    ko: { on_sale: '판매중', sold_out: '품절' },
    en: { on_sale: 'On sale', sold_out: 'Sold out' },
    ja: { on_sale: '販売中', sold_out: '売り切れ' },
    'zh-CN': { on_sale: '在售', sold_out: '售罄' },
};

/** ProductListResource 형태의 상품 목록을 만든다. */
function products(locale, count = 12) {
    const names = PRODUCT_NAMES[locale] || PRODUCT_NAMES.ko;
    const cats = CATEGORY_NAMES[locale] || CATEGORY_NAMES.ko;
    const labels = SALES_STATUS_LABEL[locale] || SALES_STATUS_LABEL.ko;

    return Array.from({ length: count }, (_, i) => {
        const [price, list] = PRICES[i];
        const stock = STOCK[i];
        const status = stock > 0 ? 'on_sale' : 'sold_out';
        return {
            id: i + 1,
            product_code: CODES[i],
            sku: CODES[i],
            name: names[i],
            name_localized: names[i],
            thumbnail_url: placeholder(names[i], BRANDS[i], HUES[i]),
            brand_name: BRANDS[i],
            primary_category: cats[CATEGORY_OF[i]],
            list_price: list,
            list_price_formatted: KRW(list),
            selling_price: price,
            selling_price_formatted: KRW(price),
            discount_rate: list > price ? Math.round((1 - price / list) * 100) : 0,
            multi_currency_selling_price: {
                KRW: { value: price, formatted: KRW(price) },
                USD: { value: Math.round(price / 1350), formatted: USD(price) },
            },
            multi_currency_list_price: {
                KRW: { value: list, formatted: KRW(list) },
                USD: { value: Math.round(list / 1350), formatted: USD(list) },
            },
            stock_quantity: stock,
            sales_status: status,
            sales_status_label: labels[status],
            display_status: 'visible',
            categories: [],
            labels: [],
            review_count: 8 + i,
            rating_avg: 4.3 + (i % 5) / 10,
            is_wishlisted: false,
        };
    });
}

/** PublicCategoryResource 형태의 카테고리 트리를 만든다. */
function categories(locale) {
    const names = CATEGORY_NAMES[locale] || CATEGORY_NAMES.ko;
    const counts = [3, 1, 2, 2, 3, 2];
    return names.map((name, i) => ({
        id: i + 1,
        name,
        name_localized: name,
        slug: ['skincare', 'makeup', 'outer', 'shirts', 'golf', 'living'][i],
        depth: 1,
        parent_id: null,
        products_count: counts[i],
        children: [],
    }));
}

/** 상품 상세 — 목록 리소스에 상세 전용 필드를 얹은 형태. */
function productDetail(locale) {
    const list = products(locale, 12);
    const p = list[0];
    const cats = CATEGORY_NAMES[locale] || CATEGORY_NAMES.ko;
    const desc = {
        ko: '<p>피부 장벽을 정돈하는 저자극 진정 앰플입니다. 세안 후 토너 다음 단계에서 사용하세요.</p><p>용량 30ml · 전 성분은 상세 표기를 참고하세요.</p>',
        en: '<p>A low-irritation calming ampoule that helps restore the skin barrier. Use after toner.</p><p>30ml — see the full ingredient list below.</p>',
        ja: '<p>肌のバリアを整える低刺激の鎮静アンプルです。化粧水のあとにお使いください。</p><p>容量 30ml — 全成分は詳細表記をご確認ください。</p>',
        'zh-CN': '<p>低刺激舒缓安瓶，帮助修护肌肤屏障。建议在爽肤水之后使用。</p><p>容量 30ml — 全成分请参见详细说明。</p>',
    }[locale];
    const commonInfo = {
        ko: '<p>제조국 대한민국 · 사용기한 개봉 후 12개월 · 고객센터 평일 10:00–17:00</p>',
        en: '<p>Made in Korea · Use within 12 months of opening · Support Mon–Fri 10:00–17:00</p>',
        ja: '<p>製造国 韓国 · 開封後12ヶ月以内にご使用ください · サポート 平日 10:00–17:00</p>',
        'zh-CN': '<p>产地 韩国 · 开封后 12 个月内使用 · 客服 工作日 10:00–17:00</p>',
    }[locale];
    const optionName = { ko: '기본', en: 'Default', ja: '基本', 'zh-CN': '基础' }[locale];

    return {
        ...p,
        category_name: cats[0],
        description: desc,
        detail_content: desc,
        content_mode: 'html',
        images: [
            { id: 1, url: placeholder(p.name_localized, p.brand_name, HUES[0], 900, 1200), is_thumbnail: true },
            { id: 2, url: placeholder(p.name_localized, 'DETAIL', HUES[0] + 8, 900, 1200) },
            { id: 3, url: placeholder(p.name_localized, 'TEXTURE', HUES[0] + 16, 900, 1200) },
            { id: 4, url: placeholder(p.name_localized, 'PACKAGE', HUES[0] + 24, 900, 1200) },
        ],
        options: [
            { id: 1, product_id: p.id, option_code: 'STD-YTVBEA0001', option_name: optionName, option_values: {}, price_adjustment: 0, stock_quantity: 30, is_active: true },
        ],
        option_groups: [],
        common_info: { name: null, content_mode: 'html', content: commonInfo },
        shipping_fee: 3000,
        shipping_fee_formatted: KRW(3000),
        free_shipping_amount: 50000,
        free_shipping_amount_formatted: KRW(50000),
        is_wishlisted: false,
        review_count: 8,
        rating_avg: 4.6,
    };
}

/** _global 초기 상태 — _user_base 의 init_actions 가 만드는 결과값과 같은 모양. */
function globalState(locale) {
    return {
        shopBase: '/shop',
        preferredCurrency: 'KRW',
        defaultCurrency: 'KRW',
        preferredShippingCountry: 'KR',
        availableCurrencies: [
            { code: 'KRW', is_default: true, symbol: '₩' },
            { code: 'USD', exchange_rate: 0.00074, symbol: '$' },
        ],
        availableCountries: [{ code: 'KR', name: '대한민국', flag_class: 'fi fi-kr' }],
        cartCount: 2,
        currentUser: null,
        notificationUnreadCount: 0,
        homeProductsFailed: false,
        homeCategoriesFailed: false,
        recentProductIds: [],
        locale,
        // 헤더 로고·푸터 상호가 읽는 코어 설정 (없으면 레이아웃이 'Site' 로 폴백한다)
        settings: {
            general: {
                site_name: 'YUTIV',
                site_description: 'Global trend commerce',
            },
        },
        siteName: 'YUTIV',
        site_name: 'YUTIV',
        site_url: 'https://yutiv.example',
        modules: {
            'sirsoft-ecommerce': {
                basic_info: { no_route: false, route_path: 'shop' },
                language_currency: { default_currency: 'KRW', currencies: [] },
                shipping: { default_country: 'KR' },
            },
        },
        boards: [],
    };
}

module.exports = { products, categories, productDetail, globalState, placeholder, PRODUCT_NAMES, CATEGORY_NAMES };

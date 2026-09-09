/**
 * IIFE 전역 계약 검사 — 운영 활성화 실패를 막는 필수 관문.
 *
 * 왜 필요한가:
 *   코어 로더는 템플릿 식별자에서 전역 변수명을 계산하고, **그 전역에서** 컴포넌트를 꺼낸다.
 *
 *     // resources/js/core/template-engine/ComponentRegistry.ts
 *     private getGlobalVariableName(): string {
 *       return this.templateId.split(/[-_]/)
 *         .map(part => part.charAt(0).toUpperCase() + part.slice(1).toLowerCase())
 *         .join('');
 *     }
 *     const module = (window as any)[globalVarName];
 *     if (!module || typeof module !== 'object') throw ... 'Component bundle not loaded.'
 *
 *   `yutiv-commerce` ⇒ `YutivCommerce`. 원본에서 파생하면서 vite 의 `build.lib.name` 이
 *   `SirsoftBasic` 으로 남아 있었고, 스크립트가 HTTP 200 으로 내려와도 브라우저에서
 *   "Component bundle not loaded. Expected global variable: YutivCommerce" 로 초기화가
 *   실패했다. 운영은 롤백됐다.
 *
 * 이 검사는 **문자열 검색이 아니라 실제 평가**다:
 *   1. template.json 의 identifier 에서 기대 전역 이름을 코어와 같은 규칙으로 계산
 *   2. vite.config.ts 의 build.lib.name 이 그 이름과 일치하는지
 *   3. dist/js/components.iife.js 를 브라우저와 같은 방식(전역 스코프 스크립트)으로 **평가**해
 *      `globalThis.<이름>` 이 객체로 존재하는지
 *   4. components.json 이 선언한 컴포넌트가 그 전역에서 실제로 꺼내지는지
 *
 * 프리뷰 렌더러는 번들을 자기 방식으로 읽기 때문에 이 결함을 놓친다. 그래서 이 검사를
 * 프리뷰 파이프라인의 별도 필수 단계로 둔다.
 *
 * 사용:  node iife-global-check.cjs [--umd <React18 UMD 디렉토리>]
 * 종료코드: 위반이 있으면 1
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const REPO = path.resolve(__dirname, '..', '..', '..');
const TPL = path.join(REPO, 'templates', '_bundled', 'yutiv-commerce');
const CORE_REGISTRY = path.join(REPO, 'resources', 'js', 'core', 'template-engine', 'ComponentRegistry.ts');

function arg(name, fallback) {
    const i = process.argv.indexOf(name);
    return i >= 0 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}
const UMD = arg('--umd', process.env.G7_PREVIEW_UMD || path.join(__dirname, '.umd'));

const violations = [];
const fail = (m) => violations.push(m);
const notes = [];

/**
 * 코어의 getGlobalVariableName() 과 **같은 규칙**으로 기대 전역 이름을 만든다.
 * (sirsoft-admin_basic → SirsoftAdminBasic, yutiv-commerce → YutivCommerce)
 */
function expectedGlobalName(identifier) {
    return identifier
        .split(/[-_]/)
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1).toLowerCase())
        .join('');
}

// ── 0. 코어 규칙이 바뀌지 않았는지 확인 ──────────────────────────────────────
// 이 검사기는 코어 로직을 복제한다. 코어가 바뀌면 복제본도 같이 틀려지므로 원문을 확인한다.
if (fs.existsSync(CORE_REGISTRY)) {
    const core = fs.readFileSync(CORE_REGISTRY, 'utf8');
    const hasRule = /split\(\/\[-_\]\/\)/.test(core)
        && /charAt\(0\)\.toUpperCase\(\)/.test(core)
        && /slice\(1\)\.toLowerCase\(\)/.test(core);
    if (!hasRule) {
        fail('코어의 getGlobalVariableName() 규칙이 이 검사기의 복제본과 달라졌습니다 — ComponentRegistry.ts 를 확인하고 expectedGlobalName() 을 맞추세요.');
    } else {
        notes.push('코어 규칙 원문 확인: ComponentRegistry.getGlobalVariableName()');
    }
} else {
    notes.push('코어 ComponentRegistry.ts 를 찾지 못해 규칙 대조를 건너뜁니다.');
}

// ── 1. identifier → 기대 전역 이름 ──────────────────────────────────────────
const manifest = JSON.parse(fs.readFileSync(path.join(TPL, 'template.json'), 'utf8'));
const identifier = manifest.identifier;
const expected = expectedGlobalName(identifier);

if (identifier !== 'yutiv-commerce') {
    fail(`template.json identifier 가 yutiv-commerce 가 아닙니다: ${identifier}`);
}
if (expected !== 'YutivCommerce') {
    fail(`identifier '${identifier}' 에서 계산한 기대 전역 이름이 YutivCommerce 가 아닙니다: ${expected}`);
}

// ── 2. vite.config.ts 의 build.lib.name ─────────────────────────────────────
const viteConfigPath = path.join(TPL, 'vite.config.ts');
const viteConfig = fs.readFileSync(viteConfigPath, 'utf8');
const libNameMatch = viteConfig.match(/name:\s*'([^']+)'/);
if (!libNameMatch) {
    fail('vite.config.ts 에서 build.lib.name 을 찾지 못했습니다');
} else if (libNameMatch[1] !== expected) {
    fail(`vite.config.ts 의 build.lib.name 이 '${libNameMatch[1]}' 입니다 — '${expected}' 여야 합니다`);
}

// ── 3. 번들을 실제로 평가 ───────────────────────────────────────────────────
const bundlePath = path.join(TPL, 'dist', 'js', 'components.iife.js');
let evaluated = null;
let typeofGlobal = 'undefined';

if (!fs.existsSync(bundlePath)) {
    fail(`번들이 없습니다: ${bundlePath}`);
} else {
    // 번들은 react / react-dom / react/jsx-runtime 을 전역으로 기대한다 (vite external + globals).
    // 브라우저와 같은 조건을 만들기 위해 React UMD 를 먼저 올린다.
    const umdFiles = [
        'react.production.min.js',
        'react-dom.production.min.js',
    ].map((f) => path.join(UMD, f));

    if (umdFiles.some((f) => !fs.existsSync(f))) {
        fail(`React UMD 가 없어 번들을 평가할 수 없습니다 (${UMD}). README 의 준비 항목을 따르세요. — 평가 없이 통과시키지 않습니다.`);
    } else {
        const noop = () => {};
        const el = () => ({
            nodeType: 1, style: {}, classList: { add: noop, remove: noop, contains: () => false },
            setAttribute: noop, getAttribute: () => null, appendChild: (c) => c, removeChild: (c) => c,
            addEventListener: noop, removeEventListener: noop, querySelector: () => null,
            querySelectorAll: () => [], children: [], childNodes: [],
        });
        const sandbox = {
            console, setTimeout, clearTimeout, setInterval, clearInterval, queueMicrotask,
            TextEncoder, TextDecoder, URL, URLSearchParams, Intl, Promise, Math, JSON, Date,
            document: {
                nodeType: 9, documentElement: el(), body: el(), head: el(),
                createElement: el, createElementNS: el, createTextNode: (t) => ({ nodeType: 3, textContent: String(t) }),
                createDocumentFragment: el, createTreeWalker: () => ({ nextNode: () => null }),
                querySelector: () => null, querySelectorAll: () => [], getElementById: () => null,
                addEventListener: noop, removeEventListener: noop,
                implementation: { createHTMLDocument: () => ({ body: el(), createElement: el, createElementNS: el, documentElement: el() }) },
            },
            navigator: { userAgent: 'node' },
            location: { href: 'http://localhost/', pathname: '/', search: '', hash: '', origin: 'http://localhost' },
            localStorage: { getItem: () => null, setItem: noop, removeItem: noop },
            sessionStorage: { getItem: () => null, setItem: noop, removeItem: noop },
            addEventListener: noop, removeEventListener: noop,
            matchMedia: (q) => ({ matches: false, media: q, addEventListener: noop, removeEventListener: noop }),
            getComputedStyle: () => ({ getPropertyValue: () => '' }),
            requestAnimationFrame: (cb) => setTimeout(cb, 0), cancelAnimationFrame: clearTimeout,
            fetch: () => Promise.reject(new Error('check: network disabled')),
            NodeFilter: { SHOW_ELEMENT: 1, SHOW_TEXT: 4 },
            DOMParser: function DOMParser() { this.parseFromString = () => ({ body: el(), documentElement: el() }); },
            trustedTypes: undefined,
        };
        sandbox.window = sandbox;
        sandbox.self = sandbox;
        sandbox.globalThis = sandbox;

        const ctx = vm.createContext(sandbox);
        try {
            for (const f of umdFiles) {
                vm.runInContext(fs.readFileSync(f, 'utf8'), ctx, { filename: path.basename(f) });
            }
            // vite.config.ts 의 rollupOptions.output.globals 와 같은 이름으로 jsx-runtime 을 올린다
            vm.runInContext(
                `globalThis.ReactJSXRuntime = {
                    jsx: (t, p, k) => React.createElement(t, k === undefined ? p : Object.assign({ key: k }, p)),
                    jsxs: (t, p, k) => React.createElement(t, k === undefined ? p : Object.assign({ key: k }, p)),
                    Fragment: React.Fragment,
                };`,
                ctx,
                { filename: 'jsx-runtime-shim.js' }
            );

            // ★ 핵심: 번들을 **전역 스코프 스크립트로 평가**한다 (브라우저의 <script> 와 같은 조건).
            //   require() 나 모듈 래핑을 쓰면 전역 노출 여부를 검증할 수 없다.
            vm.runInContext(fs.readFileSync(bundlePath, 'utf8'), ctx, { filename: 'components.iife.js' });

            // 코어 로더와 **완전히 같은 방식**으로 꺼낸다: window[globalVarName]
            evaluated = vm.runInContext(`(typeof window[${JSON.stringify(expected)}])`, ctx);
            typeofGlobal = evaluated;

            if (evaluated !== 'object') {
                // 실제로 어떤 전역이 생겼는지 보여 준다 — 진단이 쉬워진다
                const leaked = vm.runInContext(
                    `Object.keys(globalThis).filter(k => /^[A-Z]/.test(k) && typeof globalThis[k] === 'object' && globalThis[k] && !['React','ReactDOM','ReactJSXRuntime','JSON','Math','Intl','Promise','Date','URL','URLSearchParams','TextEncoder','TextDecoder','NodeFilter','DOMParser'].includes(k))`,
                    ctx
                );
                fail(
                    `번들을 평가했지만 window.${expected} 가 객체가 아닙니다 (typeof = ${evaluated}). ` +
                    `실제로 노출된 전역: ${JSON.stringify(leaked)} — vite.config.ts 의 build.lib.name 을 고치고 재빌드하세요.`
                );
            } else {
                // 4. 선언된 컴포넌트가 실제로 꺼내지는지 (코어의 registerComponentsFromManifest 와 같은 경로)
                const componentsManifest = JSON.parse(fs.readFileSync(path.join(TPL, 'components.json'), 'utf8'));
                const declared = [];
                for (const group of Object.values(componentsManifest.components || {})) {
                    if (!Array.isArray(group)) continue;
                    for (const meta of group) {
                        const n = typeof meta === 'string' ? meta : meta && meta.name;
                        if (n) declared.push(n);
                    }
                }
                const missing = vm.runInContext(
                    `(${JSON.stringify([...new Set(declared)])}).filter(n => window[${JSON.stringify(expected)}][n] === undefined)`,
                    ctx
                );
                notes.push(`선언 컴포넌트 ${new Set(declared).size}종 중 전역에서 확인되지 않은 것 ${missing.length}종`);
                if (missing.length) {
                    fail(`components.json 이 선언한 컴포넌트가 번들 전역에 없습니다 (${missing.length}종): ${missing.slice(0, 8).join(', ')}`);
                }
            }
        } catch (e) {
            fail(`번들 평가 중 예외: ${e.message}`);
        }
    }
}

// ── 4. 번들 선두의 전역 이름 (보조 확인 — 평가 결과가 주 판정) ──────────────
if (fs.existsSync(bundlePath)) {
    const head = fs.readFileSync(bundlePath, 'utf8').slice(0, 120);
    const declMatch = head.match(/^\s*var\s+([A-Za-z_$][\w$]*)\s*=/);
    const firstGlobal = declMatch ? declMatch[1] : '(var 선언 형태 아님)';
    notes.push(`번들 선두 전역 선언: ${firstGlobal}`);
    if (declMatch && declMatch[1] !== expected) {
        fail(`번들이 'var ${declMatch[1]}=' 로 시작합니다 — 'var ${expected}=' 여야 합니다`);
    }
}

// ── 출력 ────────────────────────────────────────────────────────────────────
console.log('=== yutiv-commerce IIFE 전역 계약 검사 ===\n');
console.log(`identifier            ${identifier}`);
console.log(`기대 전역 이름        ${expected}   (코어 getGlobalVariableName 규칙)`);
console.log(`vite build.lib.name   ${libNameMatch ? libNameMatch[1] : '(없음)'}`);
console.log(`typeof window.${expected}  ${typeofGlobal}   ← 실제 스크립트 평가 결과`);
for (const n of notes) console.log(`  · ${n}`);

console.log('');
if (violations.length) {
    console.log(`RESULT: FAIL — ${violations.length}건`);
    for (const v of violations) console.log('  - ' + v);
    process.exit(1);
}
console.log('RESULT: PASS — 위반 0건');

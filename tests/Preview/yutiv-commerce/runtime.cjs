/**
 * yutiv-commerce 프리뷰 런타임 — 템플릿 dist 번들을 Node 안에서 실제로 실행하기 위한 샌드박스.
 *
 * 왜 필요한가:
 *   템플릿의 composite 컴포넌트(Header/Footer/SearchBar/Pagination 등)는 문구를
 *   레이아웃 JSON 이 아니라 **런타임 전역** `window.G7Core.t(key, params)` 로 가져온다.
 *   (예: src/components/composite/Header.tsx —
 *        `const t = (key, params) => window.G7Core?.t?.(key, params) ?? key;`)
 *   G7Core 가 없으면 이 컴포넌트들은 **번역키를 그대로 출력**한다. 실제로
 *   `nav.home`, `auth.login`, `common.search_placeholder` 가 화면에 노출됐던 원인이
 *   바로 이것이다. 따라서 프리뷰는 문자열을 치환해 감추는 대신 **런타임 계약을 그대로
 *   구현**해야 한다 — 이 파일이 그 구현이다.
 *
 * 구현 범위(런타임 계약과 1:1):
 *   - G7Core.t(key, params)          템플릿 lang/{locale}.json 에서 조회 + {{param}} 치환
 *   - G7Core.state.get/getGlobal/getLocal
 *   - G7Core.dispatch / refetchDataSource / componentEvent / createLogger / api
 *   위 목록은 `grep -rho "G7Core[?.]*[a-zA-Z_.]*" src` 결과와 일치시킨다.
 *
 * React 19 는 UMD 빌드를 배포하지 않으므로 React 18 UMD 로 렌더한다. 이 템플릿은
 * React 19 전용 API(useActionState/useOptimistic/use 등)를 쓰지 않아 렌더 결과가 같다.
 * UMD 파일 위치는 `--umd <dir>` 또는 env `G7_PREVIEW_UMD` 로 지정한다(README 참조).
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

function noop() {}

/** 최소 DOM 노드 — 번들 초기화(DOMPurify 등)가 만지는 표면만 채운다. */
function makeEl(tag) {
    return {
        tagName: String(tag || 'div').toUpperCase(),
        nodeType: 1,
        style: {},
        dataset: {},
        classList: { add: noop, remove: noop, contains: () => false, toggle: noop },
        attributes: {},
        childNodes: [],
        children: [],
        firstChild: null,
        parentNode: null,
        innerHTML: '',
        textContent: '',
        setAttribute(k, v) { this.attributes[k] = String(v); },
        getAttribute(k) { return Object.prototype.hasOwnProperty.call(this.attributes, k) ? this.attributes[k] : null; },
        hasAttribute(k) { return Object.prototype.hasOwnProperty.call(this.attributes, k); },
        removeAttribute(k) { delete this.attributes[k]; },
        appendChild(c) { this.childNodes.push(c); this.children.push(c); this.firstChild = this.childNodes[0]; return c; },
        removeChild(c) { this.childNodes = this.childNodes.filter((x) => x !== c); this.children = this.childNodes.slice(); return c; },
        insertBefore(c) { return this.appendChild(c); },
        cloneNode() { return makeEl(tag); },
        addEventListener: noop,
        removeEventListener: noop,
        dispatchEvent: () => true,
        querySelector: () => null,
        querySelectorAll: () => [],
        getElementsByTagName: () => [],
        contains: () => false,
        remove: noop,
        focus: noop,
        blur: noop,
        click: noop,
    };
}

/**
 * 번역 조회 — 엔진의 `$t:` 해석과 `G7Core.t()` 가 같은 사전을 본다.
 *
 * @param {object} bag   네임스페이스별로 펼친 번역 사전
 * @param {string} key   dot-path 키
 * @param {object} params `{{name}}` 치환 파라미터
 */
function translate(bag, key, params) {
    const raw = String(key ?? '').trim();
    const value = raw.split('.').reduce((a, k) => (a && typeof a === 'object' ? a[k] : undefined), bag);
    if (typeof value !== 'string') {
        return null; // 미등록 키 — 호출부가 실패를 알 수 있게 null 을 돌려준다
    }
    if (!params) {
        return value;
    }
    return value.replace(/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/g, (m, name) =>
        Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : m
    );
}

/**
 * 템플릿의 lang/{locale}.json 을 읽어 네임스페이스별 사전으로 펼친다.
 * 진입 파일은 네임스페이스마다 `{ "$partial": "partial/{locale}/x.json" }` 를 담는다.
 */
function loadLocale(templateDir, locale) {
    const entry = JSON.parse(fs.readFileSync(path.join(templateDir, 'lang', locale + '.json'), 'utf8'));
    const bag = {};
    const walk = (node, out) => {
        for (const [k, v] of Object.entries(node)) {
            if (v && typeof v === 'object' && typeof v.$partial === 'string') {
                const p = path.join(templateDir, 'lang', v.$partial);
                out[k] = fs.existsSync(p) ? JSON.parse(fs.readFileSync(p, 'utf8')) : {};
            } else if (v && typeof v === 'object') {
                out[k] = {};
                walk(v, out[k]);
            } else {
                out[k] = v;
            }
        }
    };
    walk(entry, bag);
    return bag;
}

/**
 * 번들을 로드한 샌드박스를 만든다.
 *
 * @param {object} opts
 * @param {string} opts.templateDir  템플릿 루트
 * @param {string} opts.umdDir       React 18 UMD 파일 디렉토리
 * @param {object} opts.lang         번역 사전 (G7Core.t 가 쓴다)
 * @param {object} opts.globalState  _global 상태 (G7Core.state.getGlobal 이 쓴다)
 * @param {string[]} opts.missingKeys 미등록 번역키가 기록될 배열
 */
function createRuntime(opts) {
    const { templateDir, umdDir, lang, globalState, missingKeys } = opts;

    const documentEl = makeEl('html');
    const body = makeEl('body');
    const head = makeEl('head');
    documentEl.appendChild(head);
    documentEl.appendChild(body);

    const document = {
        nodeType: 9,
        documentElement: documentEl,
        body,
        head,
        createElement: (t) => makeEl(t),
        createElementNS: (_ns, t) => makeEl(t),
        createTextNode: (t) => ({ nodeType: 3, textContent: String(t) }),
        createDocumentFragment: () => makeEl('#fragment'),
        createTreeWalker: () => ({ nextNode: () => null, currentNode: null }),
        createNodeIterator: () => ({ nextNode: () => null }),
        querySelector: () => null,
        querySelectorAll: () => [],
        getElementById: () => null,
        getElementsByTagName: () => [],
        addEventListener: noop,
        removeEventListener: noop,
        implementation: {
            createHTMLDocument: () => ({
                body: makeEl('body'),
                createElement: (t) => makeEl(t),
                createElementNS: (_ns, t) => makeEl(t),
                documentElement: makeEl('html'),
            }),
        },
    };

    // 런타임 전역 — 컴포넌트가 실제로 부르는 표면만, 실제 계약대로 구현한다.
    const G7Core = {
        t(key, params) {
            const hit = translate(lang, key, params);
            if (hit === null) {
                missingKeys.push(String(key));
                return String(key); // 런타임과 동일한 폴백(키 반환) — 검사기가 이걸 잡는다
            }
            return hit;
        },
        state: {
            get: () => globalState,
            getGlobal: (k) => (k === undefined ? globalState : globalState[k]),
            getLocal: () => ({}),
            set: noop,
            setLocal: noop,
            subscribe: () => noop,
        },
        dispatch: noop,
        getActionDispatcher: () => ({ dispatch: noop }),
        refetchDataSource: noop,
        componentEvent: { on: () => noop, emit: noop },
        createChangeEvent: (value) => ({ target: { value } }),
        createLogger: () => ({ log: noop, warn: noop, error: noop, debug: noop }),
        // 프리뷰는 정적 렌더 — 네트워크를 쓰지 않는다.
        api: {
            get: () => Promise.reject(new Error('preview: network disabled')),
            post: () => Promise.reject(new Error('preview: network disabled')),
            patch: () => Promise.reject(new Error('preview: network disabled')),
            delete: () => Promise.reject(new Error('preview: network disabled')),
            getToken: () => null,
        },
        identity: { setLauncher: noop },
        getSlotContext: () => ({}),
        TransitionManager: { start: noop, end: noop },
        toast: { success: noop, error: noop, warning: noop, info: noop },
    };

    const win = {
        document,
        G7Core,
        location: { href: 'http://localhost/', pathname: '/', search: '', hash: '', origin: 'http://localhost' },
        navigator: { userAgent: 'node', language: 'ko-KR' },
        localStorage: { getItem: () => null, setItem: noop, removeItem: noop, clear: noop },
        sessionStorage: { getItem: () => null, setItem: noop, removeItem: noop, clear: noop },
        addEventListener: noop,
        removeEventListener: noop,
        dispatchEvent: () => true,
        matchMedia: (q) => ({ matches: false, media: q, addEventListener: noop, removeEventListener: noop, addListener: noop, removeListener: noop }),
        getComputedStyle: () => ({ getPropertyValue: () => '' }),
        requestAnimationFrame: (cb) => setTimeout(cb, 0),
        cancelAnimationFrame: clearTimeout,
        setTimeout,
        clearTimeout,
        setInterval,
        clearInterval,
        console,
        fetch: () => Promise.reject(new Error('preview: network disabled')),
        Node: function Node() {},
        Element: function Element() {},
        HTMLElement: function HTMLElement() {},
        DocumentFragment: function DocumentFragment() {},
        NodeFilter: { SHOW_ELEMENT: 1, SHOW_TEXT: 4, SHOW_COMMENT: 128 },
        trustedTypes: undefined,
        TextEncoder,
        TextDecoder,
        Promise, Object, Array, Map, Set, WeakMap, WeakSet, Symbol,
        JSON, Math, Date, RegExp, Error, TypeError, String, Number, Boolean,
        queueMicrotask, URL, URLSearchParams, AbortController, Intl,
        DOMParser: function DOMParser() {
            this.parseFromString = () => ({ body: makeEl('body'), documentElement: makeEl('html') });
        },
    };
    win.window = win;
    win.self = win;
    win.globalThis = win;

    const ctx = vm.createContext(win);
    const run = (file, label) => {
        if (!fs.existsSync(file)) {
            throw new Error(
                `프리뷰 런타임에 필요한 파일이 없습니다: ${file}\n` +
                'React 18 UMD 를 받아 --umd 로 지정하세요 (tests/Preview/yutiv-commerce/README.md 참조).'
            );
        }
        vm.runInContext(fs.readFileSync(file, 'utf8'), ctx, { filename: label });
    };

    run(path.join(umdDir, 'react.production.min.js'), 'react.umd.js');
    run(path.join(umdDir, 'react-dom.production.min.js'), 'react-dom.umd.js');
    run(path.join(umdDir, 'react-dom-server-legacy.browser.production.min.js'), 'react-dom-server.umd.js');

    // 번들이 external 로 기대하는 jsx-runtime 전역 (템플릿 vite.config.ts 의 globals 와 동일)
    vm.runInContext(
        `globalThis.ReactJSXRuntime = {
            jsx: (t, p, k) => React.createElement(t, k === undefined ? p : Object.assign({ key: k }, p)),
            jsxs: (t, p, k) => React.createElement(t, k === undefined ? p : Object.assign({ key: k }, p)),
            Fragment: React.Fragment,
        };`,
        ctx,
        { filename: 'jsx-runtime-shim.js' }
    );

    run(path.join(templateDir, 'dist/js/components.iife.js'), 'components.iife.js');

    return {
        React: win.React,
        ReactDOMServer: win.ReactDOMServer,
        // 번들의 IIFE 전역명 — 템플릿 vite.config.ts 의 `build.lib.name`
        lib: win.SirsoftBasic,
        G7Core,
    };
}

module.exports = { createRuntime, loadLocale, translate };

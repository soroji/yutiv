/**
 * G7 레이아웃 엔진의 렌더 부분집합 — 프리뷰 전용.
 *
 * 재현하는 것 (실제 엔진과 같은 규칙):
 *   - `extends` + `slots`      기반 레이아웃이 base 의 slot 자리에 주입된다
 *   - `partial`                등록 시점에 인라인된다 (상대경로 `./` 지원)
 *   - `$t:key` / `$t:key|p=v`  번역키 + 파이프 파라미터
 *   - `{{expr}}`               문자열 전체면 값으로, 섞여 있으면 보간
 *   - `if`                     falsy 면 노드를 렌더하지 않는다
 *   - `iteration`              item_var / index_var 를 스코프에 넣고 반복
 *   - `responsive`             mobile/tablet/desktop/portable + `0-639` 형식 범위
 *   - `extension_point`        확장 미등록 상태 = `default` 트리
 *   - `_global` / `_local` / `_computed` / `_isolated` / `query` / `route` 스코프
 *
 * 재현하지 않는 것 (프리뷰의 한계 — 보고서에 명시):
 *   - actions / 데이터소스 fetch / 클라이언트 effect / 상태 변화
 *   - CSS 미디어쿼리 (뷰포트별 그리드 열 수는 브라우저가 결정한다)
 */
const fs = require('fs');
const path = require('path');

const exprCache = new Map();

/** 레이아웃 표현식 평가 — 실패는 undefined 로 흡수하되 호출부가 집계할 수 있게 기록한다. */
function evalExpr(src, scope, onError) {
    let fn = exprCache.get(src);
    if (!fn) {
        fn = new Function('__scope', 'with (__scope) { return (' + src + '); }');
        exprCache.set(src, fn);
    }
    try {
        return fn(scope);
    } catch (e) {
        if (onError) onError(src, e);
        return undefined;
    }
}

/**
 * 값 해석기를 만든다.
 *
 * @param {object} opts
 * @param {(key:string, params?:object)=>string} opts.t  번역 함수 (런타임 G7Core.t 와 동일 사전)
 * @param {(src:string, err:Error)=>void} opts.onExprError
 */
function makeResolver(opts) {
    const { t, onExprError } = opts;

    /** `$t:key|a={{expr}}|b=literal` 을 해석한다 (문자열 전체가 하나의 토큰일 때). */
    const resolveT = (token, scope) => {
        const body = token.slice(3);
        const parts = body.split('|');
        const key = parts[0].trim();
        const params = {};
        for (const p of parts.slice(1)) {
            const eq = p.indexOf('=');
            if (eq < 0) continue;
            const name = p.slice(0, eq).trim();
            const raw = p.slice(eq + 1).trim();
            const m = raw.match(/^\{\{([\s\S]+)\}\}$/);
            params[name] = m ? evalExpr(m[1], scope, onExprError) : raw;
        }
        return t(key, parts.length > 1 ? params : undefined);
    };

    /**
     * 문자열 중간에 섞인 `$t:key` 도 치환한다.
     *
     * 실제 레이아웃에 이런 자리가 있다 — 예:
     *   `"$t:shop.product.total_amount ({{_local.noOptionQuantity ?? 1}}$t:shop.product.count_unit)"`
     *   (partials/shop/detail/_purchase_card.json)
     * 문자열 시작만 보고 통째로 키로 취급하면 키 전체가 미등록으로 잡힌다.
     */
    const INLINE_T = /\$t:[A-Za-z0-9_.\-]+/g;
    const resolveInlineT = (s, scope) => s.replace(INLINE_T, (tok) => resolveT(tok, scope));

    /**
     * 문자열 전체가 파이프 파라미터까지 포함한 단일 `$t:` 토큰인가.
     *
     * 파라미터 값에는 공백이 들어간다 — 실제 예:
     *   `$t:footer.copyright|siteName={{_global.settings?.general?.site_name ?? '그누보드7'}}`
     * 그래서 첫 `|` 이후는 통째로 파라미터로 본다. `|` 가 없으면 키 뒤가 곧 문자열 끝이어야
     * 하므로, `"$t:a.b (텍스트)"` 처럼 뒤에 내용이 붙은 문자열은 인라인 치환 경로로 간다.
     */
    const isWholeToken = (s) => /^\$t:[A-Za-z0-9_.\-]+(\|[\s\S]*)?$/.test(s);

    const resolve = (v, scope) => {
        if (typeof v === 'string') {
            if (isWholeToken(v)) return resolveT(v, scope);

            if (v.includes('{{')) {
                const whole = v.match(/^\{\{([\s\S]+)\}\}$/);
                if (whole) {
                    const out = evalExpr(whole[1], scope, onExprError);
                    // 표현식이 번역키를 고르는 자리(삼항 등)도 실제 엔진처럼 번역한다
                    return typeof out === 'string' && out.includes('$t:') ? resolveInlineT(out, scope) : out;
                }
                const interpolated = v.replace(/\{\{([\s\S]+?)\}\}/g, (_m, e) => {
                    const r = evalExpr(e, scope, onExprError);
                    if (r === undefined || r === null) return '';
                    return String(r);
                });
                return interpolated.includes('$t:') ? resolveInlineT(interpolated, scope) : interpolated;
            }
            return v.includes('$t:') ? resolveInlineT(v, scope) : v;
        }
        if (Array.isArray(v)) return v.map((x) => resolve(x, scope));
        if (v && typeof v === 'object') {
            const o = {};
            for (const [k, val] of Object.entries(v)) o[k] = resolve(val, scope);
            return o;
        }
        return v;
    };

    return resolve;
}

/** partial 참조를 실제 노드로 치환한다 (엔진은 등록 시점에 인라인한다). */
function inlinePartials(node, baseDir, layoutsDir) {
    if (Array.isArray(node)) return node.map((n) => inlinePartials(n, baseDir, layoutsDir));
    if (!node || typeof node !== 'object') return node;

    if (node.partial) {
        const rel = node.partial;
        const p = rel.startsWith('./') ? path.join(baseDir, rel.slice(2)) : path.join(layoutsDir, rel);
        const loaded = JSON.parse(fs.readFileSync(p, 'utf8'));
        return inlinePartials(loaded, path.dirname(p), layoutsDir);
    }

    const out = {};
    for (const [k, v] of Object.entries(node)) out[k] = inlinePartials(v, baseDir, layoutsDir);
    return out;
}

function injectSlot(nodes, slotName, slotNodes) {
    return nodes.map((n) => {
        if (!n || typeof n !== 'object') return n;
        if (n.slot === slotName) return { ...n, children: slotNodes };
        if (Array.isArray(n.children)) return { ...n, children: injectSlot(n.children, slotName, slotNodes) };
        return n;
    });
}

/** `extends` 를 풀어 최종 컴포넌트 트리를 만든다. */
function buildTree(layoutsDir, layoutName) {
    const layoutPath = path.join(layoutsDir, layoutName + '.json');
    const layout = inlinePartials(JSON.parse(fs.readFileSync(layoutPath, 'utf8')), path.dirname(layoutPath), layoutsDir);
    if (!layout.extends) return layout.components || [];

    const basePath = path.join(layoutsDir, layout.extends + '.json');
    const base = inlinePartials(JSON.parse(fs.readFileSync(basePath, 'utf8')), path.dirname(basePath), layoutsDir);
    let tree = base.components || [];
    for (const [slotName, slotNodes] of Object.entries(layout.slots || {})) {
        tree = injectSlot(tree, slotName, slotNodes);
    }
    return tree;
}

/** 뷰포트에 맞는 responsive 오버라이드를 고른다. */
function pickResponsive(responsive, width) {
    const bucket = width <= 767 ? 'mobile' : width <= 1023 ? 'tablet' : 'desktop';
    if (responsive[bucket]) return responsive[bucket];
    if (width <= 1023 && responsive.portable) return responsive.portable;
    for (const [k, v] of Object.entries(responsive)) {
        const m = k.match(/^(\d+)-(\d+)$/);
        if (m && width >= +m[1] && width <= +m[2]) return v;
    }
    return null;
}

/** 렌더러를 만든다. */
function makeRenderer(opts) {
    const { React, lib, resolve, viewport, onMissingComponent } = opts;
    let keySeq = 0;

    const componentFor = (name) => {
        const C = lib[name];
        if (C) return C;
        if (onMissingComponent) onMissingComponent(name);
        return function Missing(props) {
            return React.createElement(
                'div',
                { 'data-preview-missing': name, style: { outline: '2px dashed #c00', padding: '4px', font: '11px monospace', color: '#c00' } },
                '[preview: 미노출 컴포넌트 ' + name + ']',
                props.children
            );
        };
    };

    const render = (node, scope) => {
        if (!node || typeof node !== 'object') return null;
        if (node.if !== undefined && !resolve(node.if, scope)) return null;

        let eff = node;
        if (node.responsive) {
            const override = pickResponsive(node.responsive, viewport.width);
            if (override) {
                eff = {
                    ...node,
                    props: { ...(node.props || {}), ...(override.props || {}) },
                    style: { ...(node.style || {}), ...(override.style || {}) },
                };
            }
        }

        if (eff.iteration) {
            const src = resolve(eff.iteration.source, scope) || [];
            const itemVar = eff.iteration.item_var || 'item';
            const idxVar = eff.iteration.index_var || 'index';
            const rest = { ...eff };
            delete rest.iteration;
            return (Array.isArray(src) ? src : []).map((item, i) =>
                render({ ...rest, __key: 'it' + i }, { ...scope, [itemVar]: item, [idxVar]: i })
            );
        }

        // 확장 미등록 상태의 렌더는 default 트리다.
        if (eff.type === 'extension_point') {
            const fallback = eff.default;
            if (!Array.isArray(fallback) || !fallback.length) return null;
            return fallback.map((c, i) => render({ ...c, __key: 'ep' + i }, scope)).filter(Boolean);
        }

        if (!eff.name) return null;

        const C = componentFor(eff.name);
        const props = resolve(eff.props || {}, scope) || {};
        if (eff.style) props.style = { ...(props.style || {}), ...resolve(eff.style, scope) };
        // 레이아웃 JSON 의 style 은 객체 표기가 정식이다. 일부 노드에 남아 있는
        // 문자열 표기(`"background-color: ..."`)는 React 가 거부하므로 프리뷰에서 뺀다.
        if (typeof props.style === 'string') delete props.style;
        props.key = eff.__key || eff.id || 'n' + ++keySeq;

        let children = null;
        if (eff.text !== undefined) {
            children = resolve(eff.text, scope);
        } else if (Array.isArray(eff.children) && eff.children.length) {
            children = eff.children.map((c) => render(c, scope)).filter(Boolean);
        }

        if (children === null || children === undefined || (Array.isArray(children) && !children.length)) {
            return React.createElement(C, props);
        }
        return React.createElement(C, props, children);
    };

    return render;
}

module.exports = { makeResolver, buildTree, makeRenderer, inlinePartials };

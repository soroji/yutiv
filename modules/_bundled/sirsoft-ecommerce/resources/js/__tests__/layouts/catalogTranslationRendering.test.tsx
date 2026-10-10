import React from 'react';
import * as ReactDOM from 'react-dom';
import * as ReactJSXRuntime from 'react/jsx-runtime';
import { render, screen, fireEvent, cleanup, waitFor, within } from '@testing-library/react';
import { beforeEach, afterEach, describe, it, expect, vi } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { execFileSync } from 'node:child_process';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { TranslationEngine } from '@core/template-engine/TranslationEngine';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { ActionDispatcher } from '@core/template-engine/ActionDispatcher';
import DynamicRenderer from '@core/template-engine/DynamicRenderer';

const moduleRoot = path.resolve(__dirname, '../../../..');
const project = path.resolve(moduleRoot, '../../..');
const admin = path.join(project, 'templates/_bundled/sirsoft-admin_basic');
const json = (file: string) => JSON.parse(fs.readFileSync(file, 'utf8'));
const product = json(path.join(moduleRoot, 'resources/layouts/admin/admin_ecommerce_product_form.json'));
const category = json(path.join(moduleRoot, 'resources/layouts/admin/partials/admin_ecommerce_category_index/_panel_form.json'));
const dictionary = Object.fromEntries(['ko', 'en'].map(locale => [locale, JSON.parse(execFileSync(process.env.G7_TEST_PHP ?? 'php', [path.join(moduleRoot, 'tests/fixtures/catalog-language.php'), locale], { encoding: 'utf8' }))]));

// Keep actual ancestor + preceding header nodes, bindings and actions; omit unrelated sections.
function entryRegion(node: any): any {
  if (!node || typeof node !== 'object') return null;
  if (node.children?.some((child: any) => child.name === 'CatalogTranslationPanel')) {
    const end = node.children.findIndex((child: any) => child.name === 'CatalogTranslationPanel');
    return { ...node, children: node.children.slice(0, end + 1) };
  }
  for (const value of Object.values(node)) {
    for (const child of Array.isArray(value) ? value : [value]) {
      const found = entryRegion(child); if (found) return found;
    }
  }
  return null;
}

describe('real form JSON → manifest → built IIFE → DynamicRenderer translation entry', () => {
  let fetcher: ReturnType<typeof vi.fn>;
  beforeEach(() => {
    // A new page clears engine pending state; isolate each mounted form likewise.
    for (const key of ['__g7PendingLocalState', '__g7ForcedLocalFields', '__g7SetLocalOverrideKeys']) delete (window as any)[key];
    fetcher = vi.fn(async (url: string, init: RequestInit = {}) => {
      if (String(url).includes('/components')) return { ok: true, json: async () => json(path.join(admin, 'components.json')) };
      if (String(url).endsWith('/configuration')) return { ok: true, json: async () => ({ data: { configured: true, status: 'ready' } }) };
      if (init.method === 'POST' && String(url).endsWith('/catalog-translations')) {
        const payload = JSON.parse(String(init.body));
        return { ok: true, json: async () => ({ data: { id: 'fake-job', items: payload.items.map((item: any) => ({ ...item, status: 'completed', result: `Translated ${item.source}` })) } }) };
      }
      if (String(url).endsWith('/cancel')) return { ok: true, json: async () => ({ data: { cancelled: true, items: [] } }) };
      throw new Error('Unexpected request; no catalog save or paid AI request is allowed');
    });
    vi.stubGlobal('fetch', fetcher);
  });
  afterEach(() => { cleanup(); vi.unstubAllGlobals(); });

  async function mount(kind: 'product' | 'category', edit: boolean, status = 'ready', locale = 'ko', canUpdate = true) {
    const translation = new TranslationEngine();
    const context = { templateId: 'sirsoft-admin_basic', locale };
    (translation as any).translations.set(`${context.templateId}:${locale}`, { 'sirsoft-ecommerce': dictionary[locale] });
    const form: any = { ...(edit ? { id: 7 } : {}), name: { ko: '상품 원문' }, description: { ko: '<p>설명</p>' }, meta_title: { ko: '제목' }, meta_description: { ko: '설명' }, has_options: true, options: [{ id: 31, option_name: { ko: '로즈핑크' }, option_values: [{ key: { ko: '색상' }, value: { ko: '로즈핑크' } }], selling_price: 10000, stock_quantity: 8 }] };
    const state: any = { form, ui: { optionInputs: [{ name: { ko: '색상' }, values: [{ ko: '로즈핑크' }] }] } };
    const setState = vi.fn((updates: any) => Object.assign(state, typeof updates === 'function' ? updates(state) : updates));
    (window as any).G7Core = { t: (key: string) => translation.translate(key, context), state: { getLocal: () => state }, api: { getToken: () => null }, createLogger: () => ({ log() {}, warn() {}, error() {} }) };
    const sandbox: any = { React, ReactDOM, ReactJSXRuntime, window, document, navigator, console, setTimeout, clearTimeout, setInterval, clearInterval, AbortController, crypto, fetch: (...args: any[]) => fetcher(...args) };
    vm.runInNewContext(fs.readFileSync(path.join(admin, 'dist/js/components.iife.js'), 'utf8'), sandbox);
    (window as any).SirsoftAdminBasic = sandbox.SirsoftAdminBasic;
    const registry = ComponentRegistry.createIsolatedInstance();
    await registry.loadComponents('sirsoft-admin_basic', 'admin');
    const original = fetcher.getMockImplementation()!;
    fetcher.mockImplementation(async (url: string, init: RequestInit = {}) => String(url).endsWith('/configuration') ? { ok: true, json: async () => ({ data: { configured: status === 'ready', status } }) } : original(url, init));
    const data: any = { _local: state, _global: { panelMode: edit ? 'edit' : 'create', selectedCategoryId: edit ? 7 : null }, route: { itemCode: edit ? '000007' : null }, product: { data: { ...form, abilities: { can_update: canUpdate } } }, categories: { data: { abilities: { can_update: canUpdate } } } };
    render(<DynamicRenderer componentDef={entryRegion(kind === 'product' ? product : category)} dataContext={data} translationContext={context} registry={registry} bindingEngine={new DataBindingEngine()} translationEngine={translation} actionDispatcher={new ActionDispatcher()} parentComponentContext={{ state, setState }} />);
    const label = dictionary[locale].admin.translation.title;
    const region = await screen.findByRole('region', { name: label });
    await waitFor(() => expect(within(region).getByText(dictionary[locale].admin.translation[status === 'ready' ? 'configured' : status])).toBeInTheDocument());
    return { region, label, state, setState };
  }

  it.each([['product', false], ['product', true], ['category', false], ['category', true]] as const)('%s %s form renders, toggles, selects, translates, reviews and applies without saving', async (kind, edit) => {
    const { region, label, state, setState } = await mount(kind, edit);
    expect(document.body.textContent).not.toContain('sirsoft-ecommerce.admin.translation');
    expect(fetcher.mock.calls.filter(([, init]) => init?.method === 'POST')).toHaveLength(0);
    const entry = screen.getByRole('button', { name: label });
    fireEvent.click(entry); expect(screen.queryByRole('region', { name: label })).toBeNull();
    fireEvent.click(entry); await screen.findByRole('region', { name: label });
    const activeRegion = screen.getByRole('region', { name: label });
    fireEvent.click(within(activeRegion).getByLabelText('ja'));
    fireEvent.change(within(activeRegion).getByLabelText(dictionary.ko.admin.translation.terms), { target: { value: 'YUTIV' } });
    fireEvent.click(within(activeRegion).getByRole('button', { name: dictionary.ko.admin.translation.translate }));
    const apply = await screen.findByRole('button', { name: dictionary.ko.admin.translation.apply });
    const payload = JSON.parse(String(fetcher.mock.calls.find(([, init]) => init?.method === 'POST')![1].body));
    expect(payload.kind).toBe(kind); expect(payload.entity_id).toBe(edit ? 7 : null); expect(payload.terms).toContain('YUTIV');
    expect(payload.items.every((item: any) => item.locale !== 'ja')).toBe(true);
    if (kind === 'product') expect(payload.items.some((item: any) => item.field === 'option_value')).toBe(true);
    expect(payload.items.some((item: any) => item.field === 'description')).toBe(true);
    expect(payload.items.some((item: any) => item.field === 'meta_title')).toBe(true);
    fireEvent.click(apply); await waitFor(() => expect(setState).toHaveBeenCalled());
    expect(state.form.name.en).toBe('Translated 상품 원문');
    expect(state.form.options[0].id).toBe(31); expect(state.form.options[0].selling_price).toBe(10000);
    expect(region).not.toBeNull();
  });

  it.each((['product', 'category'] as const).flatMap(kind => [false, true].flatMap(edit => ['disabled', 'not_configured', 'config_missing'].map(status => [kind, edit, status] as const))))('keeps %s %s %s entry and guidance visible but prevents execution', async (kind, edit, status) => {
    const { region } = await mount(kind, edit, status);
    expect(within(region).getByRole('button', { name: dictionary.ko.admin.translation.translate })).toBeDisabled();
    expect(fetcher.mock.calls.filter(([, init]) => init?.method === 'POST')).toHaveLength(0);
  });
  it('loads English through the same PHP partial resolver and can open a read-only edit entry', async () => {
    const { label, region } = await mount('product', true, 'ready', 'en', false);
    expect(label).toBe('AI multilingual translation');
    expect(within(region).getByRole('button', { name: dictionary.en.admin.translation.translate })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: label }));
    expect(screen.queryByRole('region', { name: label })).toBeNull();
  });
  it('renders the translated Retry-After guidance from the built IIFE without resending or changing the form', async () => {
    const { region, state, setState } = await mount('product', true);
    const original = fetcher.getMockImplementation()!;
    fetcher.mockImplementation(async (url: string, init: RequestInit = {}) => init.method === 'POST' && url.endsWith('/catalog-translations') ? { ok: false, status: 429, headers: new Headers({ 'Retry-After': '28' }), json: async () => ({ errors: { code: 'catalog_translation_rate_limited', retry_after: 28 } }) } : original(url, init));
    const before = JSON.stringify(state.form);
    fireEvent.click(within(region).getByRole('button', { name: dictionary.ko.admin.translation.translate }));
    expect(await within(region).findByRole('alert')).toHaveTextContent('요청이 많습니다. 28초 후 다시 시도해 주세요.');
    expect(within(region).getByRole('button', { name: dictionary.ko.admin.translation.translate })).toBeDisabled();
    expect(fetcher.mock.calls.filter(([, init]) => init?.method === 'POST')).toHaveLength(1);
    expect(JSON.stringify(state.form)).toBe(before);
    // Snapshot flush may emit an empty engine update; a rejected request must not apply a form.
    expect(setState.mock.calls.every(([update]) => !Object.hasOwn(update, 'form'))).toBe(true);
  });
});

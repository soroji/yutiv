import { describe, expect, it } from 'vitest';
import { applyTranslationResults, collectTranslationBindings, staleTranslationFields } from '../catalogTranslation';

const results = (bindings: ReturnType<typeof collectTranslationBindings>) => bindings.map(item => ({ ...item, status: item.source && (item.overwrite || !item.current) ? 'completed' : 'skipped', result: `Translated ${item.source}` }));
describe('catalog translation form protection', () => {
  it('never translates a simple-product internal sales unit or accepts a comma that would split an option value', () => {
    const simple = { has_options: false, options: [{ id: 1, option_name: { ko: '기본' }, option_values: [] }] };
    expect(collectTranslationBindings(simple, [], 'product', ['en'], ['option_name'], false)).toEqual([]);
    const inputs = [{ name: { ko: '색상' }, values: [{ ko: '빨강' }] }];
    const binding = collectTranslationBindings({}, inputs, 'product', ['en'], ['option_value'], false);
    const output = applyTranslationResults({}, inputs, 'product', binding, [{ ...binding[0], status: 'completed', result: 'Red, Blue' }]);
    expect(output.conflicts).toHaveLength(1); expect(output.optionInputs[0].values).toEqual(inputs[0].values);
  });
  it('fills only selected empty translations and preserves every non-text value', () => {
    const form = { name: { ko: '상품', en: 'Manual' }, description: { ko: '<p>설명</p>' }, product_code: '00001', sku: 'SKU-01', selling_price: 10000, stock_quantity: 2, category_ids: [3], currency_code: 'KRW', slug: 'original', images: [{ url: '/a.png' }] };
    const binding = collectTranslationBindings(form, [], 'product', ['en', 'ja'], ['name'], false);
    const output = applyTranslationResults(form, [], 'product', binding, results(binding));
    expect(output.form.name).toEqual({ ko: '상품', en: 'Manual', ja: 'Translated 상품' });
    expect({ ...output.form, name: form.name, translation_sources: undefined }).toEqual({ ...form, translation_sources: undefined });
    expect(form.name).toEqual({ ko: '상품', en: 'Manual' });
  });
  it('allows explicit overwrite while protecting concurrent source or target editing', () => {
    const form = { name: { ko: '상품', en: 'Manual' } };
    const binding = collectTranslationBindings(form, [], 'product', ['en'], ['name'], true);
    expect(applyTranslationResults(form, [], 'product', binding, results(binding)).form.name.en).toBe('Translated 상품');
    for (const current of [{ name: { ko: '새 원문', en: 'Manual' } }, { name: { ko: '상품', en: 'New manual' } }]) {
      const output = applyTranslationResults(current, [], 'product', binding, results(binding));
      expect(output.conflicts).toHaveLength(1); expect(output.form.name).toEqual(current.name);
    }
  });
  it('preserves saved option IDs, codes, combination relations, order, price and inventory', () => {
    const first = { id: 31, option_code: 'OPT-01', sku: '00031', option_name: { ko: '로즈핑크 S' }, option_values: [{ key: { ko: '색상' }, value: { ko: '로즈핑크' } }], stock_quantity: 8, selling_price: 10000 };
    const second = { ...first, id: 32, option_code: 'OPT-02', option_name: { ko: '코랄 S' }, option_values: [{ key: { ko: '색상' }, value: { ko: '코랄' } }] };
    const form = { options: [first, second], additional_options: [{ id: 40, name: { ko: '포장' }, sort_order: 1, values: [{ id: 41, name: { ko: '선물' }, price_adjustment: 500, is_active: true }] }] };
    const fields = ['option_name', 'option_group_name', 'option_value', 'additional_option_name', 'additional_option_value'];
    const binding = collectTranslationBindings(form, [], 'product', ['en'], fields, false);
    expect(binding.find(item => item.field === 'option_name')?.id).toContain('record-31');
    const current = { ...form, options: [second, { ...first, stock_quantity: 9 }] };
    const output = applyTranslationResults(current, [], 'product', binding, results(binding));
    expect(output.form.options.map((option: any) => option.id)).toEqual([32, 31]);
    expect(output.form.options[0].option_name.en).toBe('Translated 코랄 S');
    expect(output.form.options[1].option_name.en).toBe('Translated 로즈핑크 S');
    expect(output.form.options[1].stock_quantity).toBe(9); expect(output.form.options[1].selling_price).toBe(10000);
    expect(output.form.options[1].option_code).toBe('OPT-01'); expect(output.form.options[1].sku).toBe('00031');
    expect(output.form.additional_options[0].values[0]).toMatchObject({ id: 41, price_adjustment: 500, is_active: true, name: { en: 'Translated 선물' } });
  });
  it('uses temporary text IDs for unsaved values and does not match by array order', () => {
    const group = { name: { ko: '색상' }, values: [{ ko: '로즈핑크' }, { ko: '코랄' }] };
    const inputs = [group];
    const binding = collectTranslationBindings({}, inputs, 'product', ['en'], ['option_group_name', 'option_value'], false);
    const current = [{ ...group, values: [...group.values].reverse() }];
    const output = applyTranslationResults({}, current, 'product', binding, results(binding));
    expect(output.optionInputs[0].values).toEqual([{ ko: '코랄', en: 'Translated 코랄' }, { ko: '로즈핑크', en: 'Translated 로즈핑크' }]);
    expect(output.optionInputs[0].valueText.en).toBe('Translated 코랄, Translated 로즈핑크');
    expect(output.form.options).toBeUndefined();
  });
  it('detects replaced unsaved values, malformed result IDs and changed source after applying', () => {
    const form = { name: { ko: '원문' } }; const binding = collectTranslationBindings(form, [], 'product', ['en'], ['name'], false);
    const output = applyTranslationResults(form, [], 'product', binding, results(binding));
    expect(staleTranslationFields(output.form, [], 'product')).toEqual([]);
    expect(staleTranslationFields({ ...output.form, name: { ...output.form.name, ko: '수정 원문' } }, [], 'product')).toEqual(['name']);
    expect(applyTranslationResults(form, [], 'product', binding, [{ ...results(binding)[0], id: 'foreign' }]).conflicts).toEqual(['foreign']);
    const inputs = [{ name: { ko: '색상' }, values: [{ ko: '빨강' }] }];
    const optionBinding = collectTranslationBindings({}, inputs, 'product', ['en'], ['option_value'], false);
    expect(applyTranslationResults({}, [{ ...inputs[0], values: [{ ko: '다른 값' }] }], 'product', optionBinding, results(optionBinding)).conflicts).toHaveLength(1);
  });
  it('extends category SEO maps without changing legacy fields, hierarchy, slug or product links', () => {
    const form = { id: 9, name: { ko: '분류' }, meta_title: '기존 SEO', parent_id: 3, slug: 'category', product_ids: [5] };
    const binding = collectTranslationBindings(form, [], 'category', ['en'], ['name', 'meta_title'], false);
    const output = applyTranslationResults(form, [], 'category', binding, results(binding));
    expect(output.form).toMatchObject({ id: 9, parent_id: 3, slug: 'category', product_ids: [5], meta_title: '기존 SEO', meta_title_translations: { ko: '기존 SEO', en: 'Translated 기존 SEO' } });
    expect(output.form.categories).toBeUndefined();
  });
  it('preserves legacy Korean SEO source when only a target translation map exists', () => {
    const form = { meta_title: '기존 SEO', meta_title_translations: { en: 'Manual SEO' } };
    const binding = collectTranslationBindings(form, [], 'category', ['en', 'ja'], ['meta_title'], false);
    expect(binding.map(item => item.source)).toEqual(['기존 SEO', '기존 SEO']);
    const output = applyTranslationResults(form, [], 'category', binding, results(binding));
    expect(output.form.meta_title_translations).toEqual({ ko: '기존 SEO', en: 'Manual SEO', ja: 'Translated 기존 SEO' });
  });
  it('leaves blank source, skipped and failed entries untouched', () => {
    const form = { name: { ko: '', en: 'Manual' }, description: { ko: '설명' } };
    const binding = collectTranslationBindings(form, [], 'product', ['en'], ['name', 'description'], false);
    const output = applyTranslationResults(form, [], 'product', binding, results(binding).map(item => ({ ...item, status: item.field === 'description' ? 'failed' : item.status })));
    expect(output.form.name).toEqual(form.name); expect(output.form.description).toEqual(form.description); expect(output.applied).toBe(0);
  });
});

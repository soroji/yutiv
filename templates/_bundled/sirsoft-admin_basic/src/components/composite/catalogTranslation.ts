export type CatalogForm = Record<string, any>;
export type TranslationItem = { id: string; field: string; source: string; current: string; locale: string; html: boolean; overwrite: boolean; status?: string; result?: string | null; error?: string | null };
export type TranslationBinding = TranslationItem & { locate: (form: CatalogForm, inputs: any[]) => { owner: any; key: string | number }[] };
const tokens = new WeakMap<object, string>();
const token = (object: object) => { let id = tokens.get(object); if (!id) { id = crypto.randomUUID(); tokens.set(object, id); } return id; };
const localized = (value: any): Record<string, string> => typeof value === 'string' ? { ko: value } : value && typeof value === 'object' && !Array.isArray(value) ? value : {};

/** Only display-text maps are enumerated. Never recurse into codes, money, relationships or categories. */
function maps(form: CatalogForm, inputs: any[], kind: string) {
  const result: { field: string; owner: any; key: string | number; root?: boolean }[] = [];
  for (const field of ['name', 'description', 'meta_title', 'meta_description']) {
    result.push({ field, owner: form, key: kind === 'category' && field.startsWith('meta_') ? `${field}_translations` : field, root: true });
  }
  if (kind === 'product') {
    result.push({ field: 'meta_keywords', owner: form, key: 'meta_keywords_translations', root: true });
    for (const group of [...(form.option_groups ?? []), ...inputs]) {
      result.push({ field: 'option_group_name', owner: group, key: 'name' });
      for (let index = 0; index < (group.values ?? []).length; index++) if (typeof group.values[index] === 'object') result.push({ field: 'option_value', owner: group.values, key: index });
    }
    for (const option of form.has_options === false ? [] : form.options ?? []) {
      result.push({ field: 'option_name', owner: option, key: 'option_name' });
      // Legacy object combinations must be edited through existing conversion first.
      for (const pair of Array.isArray(option.option_values) ? option.option_values : []) {
        result.push({ field: 'option_group_name', owner: pair, key: 'key' });
        result.push({ field: 'option_value', owner: pair, key: 'value' });
      }
    }
    for (const group of form.additional_options ?? []) {
      result.push({ field: 'additional_option_name', owner: group, key: 'name' });
      for (const value of group.values ?? []) result.push({ field: 'additional_option_value', owner: value, key: 'name' });
    }
  }
  return result;
}

function valueFor(map: ReturnType<typeof maps>[number], form: CatalogForm): Record<string, string> {
  const value = localized(map.owner[map.key]);
  if (map.root) {
    if (map.field === 'meta_keywords') return { ko: (form.meta_keywords ?? []).join(', '), ...value };
    if (String(map.key).endsWith('_translations')) return { ...localized(form[map.field]), ...value };
  }
  return value;
}

function mapIdentity(map: ReturnType<typeof maps>[number]): string {
  if (map.root) return `field-${map.field}`;
  if (map.owner.id && ['option_name', 'additional_option_name', 'additional_option_value'].includes(map.field)) return `record-${map.owner.id}`;
  return typeof map.owner[map.key] === 'object' && map.owner[map.key] !== null ? token(map.owner[map.key]) : token(map.owner) + '-' + String(map.key);
}

export function collectTranslationBindings(form: CatalogForm, inputs: any[], kind: string, locales: string[], fields: string[], overwrite: boolean): TranslationBinding[] {
  const entries = new Map<string, TranslationBinding>();
  for (const map of maps(form, inputs, kind)) {
    if (!fields.includes(map.field)) continue;
    const value = valueFor(map, form);
    // Saved option IDs/codes stay intact. Unsaved text objects receive session-local UUIDs.
    // Lookup follows object identity, so reordering is safe; replacement/edit is a conflict.
    const identity = mapIdentity(map);
    for (const locale of locales) {
      const id = `${map.field}:${identity}:${locale}`;
      if (entries.has(id)) continue;
      entries.set(id, {
        id, field: map.field, source: value.ko ?? '', current: value[locale] ?? '', locale,
        html: map.field === 'description' && (kind === 'category' || form.description_mode === 'html'), overwrite,
        locate: (currentForm, currentInputs) => maps(currentForm, currentInputs, kind).filter(candidate => {
          if (candidate.field !== map.field) return false;
          if (map.root) return candidate.root && candidate.key === map.key;
          return mapIdentity(candidate) === identity;
        }),
      });
    }
  }
  return [...entries.values()];
}

export function sourceFingerprint(value: string): string {
  let hash = 2166136261;
  for (let i = 0; i < value.length; i++) hash = Math.imul(hash ^ value.charCodeAt(i), 16777619);
  return (hash >>> 0).toString(16).padStart(8, '0');
}

export function staleTranslationFields(form: CatalogForm, inputs: any[], kind: string): string[] {
  const stale = new Set<string>();
  for (const entry of collectTranslationBindings(form, inputs, kind, ['en', 'ja', 'zh-CN'], ['name', 'description', 'meta_title', 'meta_description', 'meta_keywords', 'option_name', 'option_group_name', 'option_value', 'additional_option_name', 'additional_option_value'], false)) {
    const previous = form.translation_sources?.[`${entry.field}:${entry.locale}`];
    if (previous && entry.source && previous !== fieldFingerprint(form, inputs, kind, entry.field)) stale.add(entry.field);
  }
  return [...stale];
}

function fieldFingerprint(form: CatalogForm, inputs: any[], kind: string, field: string): string {
  return sourceFingerprint(JSON.stringify(maps(form, inputs, kind).filter(map => map.field === field).map(map => valueFor(map, form).ko ?? '')));
}

/** Apply only reviewed text against latest input. Never generate combinations or replace selling units. */
export function applyTranslationResults(form: CatalogForm, inputs: any[], kind: string, bindings: TranslationBinding[], results: TranslationItem[]) {
  const replacements = new Map<object, Map<string | number, Record<string, string>>>();
  const root: Record<string, Record<string, string>> = {};
  const conflicts: string[] = [];
  const applied: TranslationBinding[] = [];
  for (const result of results) {
    if (result.status !== 'completed' || typeof result.result !== 'string') continue;
    if (result.field === 'option_value' && (!result.result.trim() || result.result.includes(','))) { conflicts.push(result.id); continue; }
    const binding = bindings.find(entry => entry.id === result.id && entry.locale === result.locale && entry.field === result.field);
    if (!binding) { conflicts.push(result.id); continue; }
    const locations = binding.locate(form, inputs);
    if (!locations.length || locations.some(location => {
      const map = { ...location, field: binding.field, root: location.owner === form };
      const value = valueFor(map, form);
      return (value.ko ?? '') !== binding.source || (value[binding.locale] ?? '') !== binding.current;
    })) { conflicts.push(result.id); continue; }
    for (const location of locations) {
      const value = valueFor({ ...location, field: binding.field, root: location.owner === form }, form);
      if (location.owner === form) root[String(location.key)] = { ...(root[String(location.key)] ?? value), [binding.locale]: result.result };
      else {
        const changes = replacements.get(location.owner) ?? new Map();
        changes.set(location.key, { ...(changes.get(location.key) ?? value), [binding.locale]: result.result });
        replacements.set(location.owner, changes);
      }
    }
    applied.push(binding);
  }
  const replace = (value: any): any => {
    if (!value || typeof value !== 'object') return value;
    const changes = replacements.get(value);
    if (Array.isArray(value)) {
      const next = value.map((item, index) => changes?.get(index) ?? replace(item));
      return next.every((item, index) => item === value[index]) ? value : next;
    }
    const entries = Object.entries(value).map(([key, item]) => [key, changes?.get(key) ?? replace(item)] as const);
    return entries.every(([key, item]) => item === value[key]) ? value : Object.fromEntries(entries);
  };
  const nextForm = { ...replace(form), ...root };
  const nextInputs = replace(inputs).map((group: any) => ({ ...group, valueText: Object.fromEntries([...new Set(['ko', 'en', 'ja', 'zh-CN', ...Object.keys(group.valueText ?? {}), ...(group.values ?? []).flatMap((value: any) => Object.keys(localized(value)))])].map(locale => [locale, (group.values ?? []).map((value: any) => localized(value)[locale] ?? '').join(', ')])) }));
  const sources = { ...(form.translation_sources ?? {}) };
  for (const entry of applied) {
    const related = bindings.filter(item => item.field === entry.field && item.locale === entry.locale && item.source && (item.overwrite || !item.current));
    if (related.every(item => applied.some(done => done.id === item.id))) sources[`${entry.field}:${entry.locale}`] = fieldFingerprint(nextForm, nextInputs, kind, entry.field);
  }
  nextForm.translation_sources = sources;
  return { form: nextForm, optionInputs: nextInputs, conflicts, applied: applied.length };
}

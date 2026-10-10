import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const path = resolve(process.cwd(), '../../../modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_list/_partial_product_datagrid.json');
const grid = JSON.parse(readFileSync(path, 'utf8')).children[0].props;
const inputs = (node: any): any[] => [
  ...(node.name === 'CompactNumberInput' ? [node] : []),
  ...(node.children ?? node.cellChildren ?? []).flatMap(inputs),
];

describe('product list density layout and editing contract', () => {
  it.each([
    ['list_price_formatted', 'list_price', '96px'],
    ['selling_price_formatted', 'selling_price', '96px'],
    ['stock_quantity', 'stock_quantity', '68px'],
  ])('keeps %s as an untruncated numeric editor using the original update action', (field, key, width) => {
    const column = grid.columns.find((entry: any) => entry.field === field);
    const input = inputs(column)[0];
    expect(column.width).toBe(width);
    expect(column.compactPadding).toBe(true);
    expect(input.props.type).toBe('number');
    expect(input.props.value).toBe(`{{row.${key}}}`);
    expect(input.props.className).toContain('text-right');
    expect(input.props.className).not.toContain('truncate');
    expect(input.props.style).toBeUndefined();
    expect(input.name).toBe('CompactNumberInput');
    expect(input.actions).toEqual([{ type: 'change', handler: 'sirsoft-ecommerce.updateProductField', params: { productId: '{{row.id}}', field: key, value: '{{$event.target.value}}' }, debounce: 300 }]);
    expect(input.props.disabled).toBe(key === 'stock_quantity' ? '{{row.has_options || row.abilities?.can_update !== true}}' : '{{row.abilities?.can_update !== true}}');
  });

  it('opts into compact actions and list-only product name controls', () => {
    expect(grid).toMatchObject({ fitColumns: true, actionsWidth: '92px', actionsIconOnly: true, stickyActions: true, primaryActionId: 'edit' });
    const name = grid.columns.find((column: any) => column.field === 'name');
    expect(name.width).toBe('240px');
    const multilingual = name.cellChildren[0].children[0].children[0];
    expect(multilingual.props).toMatchObject({ layout: 'list', identifierText: '{{row.product_code ?? ""}}', value: '{{row.name}}' });
    expect(multilingual.actions[0]).toMatchObject({ handler: 'sirsoft-ecommerce.updateProductField', params: { field: 'name', value: '{{$event.target.value}}' }, debounce: 300 });
  });
});

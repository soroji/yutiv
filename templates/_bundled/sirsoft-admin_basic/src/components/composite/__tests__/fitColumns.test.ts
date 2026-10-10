import { describe, expect, it } from 'vitest';
import { fitColumnWidths } from '../fitColumns';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
const columns = JSON.parse(readFileSync(resolve(process.cwd(), '../../../modules/_bundled/sirsoft-ecommerce/resources/layouts/admin/partials/admin_ecommerce_product_list/_partial_product_datagrid.json'), 'utf8')).children[0].props.columns;
describe('fit column allocation (geometry calculations, not a browser measurement)', () => {
  it.each([1920,1440,1366])('allocates every column at viewport %s with either sidebar width', viewport => {
    for (const sidebar of [288,64]) {
      const available = viewport - sidebar - 80;
      const fitted = fitColumnWidths(columns, available, 150, 'name', 0);
      expect(fitted.tableWidth).toBe(available);
      expect(fitted.widths.reduce((sum, width) => sum + width, 150)).toBeCloseTo(available, 8);
      expect(fitted.widths).toHaveLength(columns.length);
      expect(fitted.widths.every(width => width > 0)).toBe(true);
      const width = (field: string) => fitted.widths[columns.findIndex((column: {field: string}) => column.field === field)];
      expect(width('sales_status')).toBeGreaterThanOrEqual(84);
      expect(width('list_price_formatted')).toBeGreaterThanOrEqual(60);
      expect(width('min_purchase_qty')).toBeGreaterThanOrEqual(32);
    }
  });
  it('reserves maximum numeric widths instead of compressing or hiding them', () => {
    const minimums = { list_price_formatted: 148, selling_price_formatted: 148, stock_quantity: 97 };
    for (const viewport of [1920, 1440, 1366]) for (const sidebar of [288, 64]) {
      const available = viewport - sidebar - 80;
      const fitted = fitColumnWidths(columns, available, 150, 'name', 0, minimums);
      expect(fitted.minimumRequiredWidth).toBe(1221);
      expect(fitted.tableWidth).toBe(Math.max(available, 1221));
      for (const [field, minimum] of Object.entries(minimums)) expect(fitted.widths[columns.findIndex((column: {field: string}) => column.field === field)]).toBeGreaterThanOrEqual(minimum);
      expect(fitted.widths.reduce((sum, width) => sum + width, 150)).toBeCloseTo(fitted.tableWidth);
    }
  });
  it('allows a readable table to scroll in a narrow container', () => {
    expect(fitColumnWidths(columns, 768, 150, 'name').tableWidth).toBe(992);
  });
});

/** All widths include cell padding. Below the readable table width allow internal scrolling. */
export function fitColumnWidths(columns: { field: string; width?: string }[], containerWidth: number, extras: number, flexibleField: string, minimumWidth = 960, contentMinimums: Record<string, number> = {}) {
  const flexibleIndex = columns.findIndex(column => column.field === flexibleField);
  const readable: Record<string, number> = { thumbnail: 56, categories: 52, list_price_formatted: 60, selling_price_formatted: 60, stock_quantity: 44, sales_status: 84, display_status: 84, brand_name: 40, min_purchase_qty: 32, max_purchase_qty: 32, shipping_policy_name: 54, created_at: 72, updated_at: 72 };
  const minimums = columns.map((column, index) => Math.max(contentMinimums[column.field] ?? 0, index === flexibleIndex ? 100 : readable[column.field] ?? Math.min(64, parseFloat(column.width ?? '80') || 80)));
  const total = minimums.reduce((sum, width) => sum + width, 0);
  const minimumRequiredWidth = total + extras;
  const tableWidth = Math.max(minimumWidth, containerWidth, minimumRequiredWidth);
  const available = Math.max(0, tableWidth - extras);
  if (available <= total) return { tableWidth, minimumRequiredWidth, widths: minimums };
  const surplus = available - total;
  const gaps = columns.map((column, index) => index === flexibleIndex ? 0 : Math.max(0, (parseFloat(column.width ?? '80') || 80) - minimums[index]));
  const gapTotal = gaps.reduce((sum, gap) => sum + gap, 0);
  const expansion = Math.min(gapTotal, flexibleIndex >= 0 ? surplus * 0.75 : surplus);
  const widths = minimums.map((width, index) => width + expansion * gaps[index] / (gapTotal || 1));
  if (flexibleIndex >= 0) widths[flexibleIndex] += surplus - expansion;
  else widths.forEach((_, index) => { widths[index] += (surplus - expansion) / (widths.length || 1); });
  return { tableWidth, minimumRequiredWidth, widths };
}

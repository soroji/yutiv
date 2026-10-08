/** Presentation only. No order, reward or tenant calculations belong here. */
export interface StorefrontSlots {
  logoUrl?: string;
  bannerUrl?: string;
  selectedProductCodes?: readonly string[];
  rewardText?: string;
  /** Opaque server-issued reference. A future provider MUST validate it on the server. */
  orderContextReference?: string;
}
export function shopBase(): string {
  const state = (window as any).G7Core?.state?.get?.() ?? {};
  const info = state.modules?.['sirsoft-ecommerce']?.basic_info;
  if (info?.no_route) return '';
  return '/' + String(info?.route_path ?? 'shop').replace(/^\/+|\/+$/g, '');
}
export const placeholder = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 400"><rect width="400" height="400" fill="#F4F0E6"/><path d="M145 145h110v110H145zM145 225l35-35 35 35 20-20 20 20" fill="none" stroke="#C9B08D" stroke-width="2"/><circle cx="225" cy="175" r="8" fill="#C9B08D"/></svg>');
/** Only same-origin or explicitly configured storage origins. Never forward tokens. */
export function safeMediaUrl(value?: string | null): string | undefined {
  if (!value || /[\\\u0000-\u0020]/.test(value) || value.startsWith('//')) return undefined;
  if (/^(blob:)/i.test(value)) return value;
  if (value === placeholder) return value;
  try {
    const origin = window.location.origin;
    const url = new URL(value, origin);
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) return undefined;
    const allowed = (window as any).G7Core?.state?.get?.()?.storefront?.allowedStorageOrigins ?? [];
    return url.origin === origin || (url.protocol === 'https:' && Array.isArray(allowed) && allowed.includes(url.origin)) ? value : undefined;
  } catch { return value.startsWith('/') && !value.startsWith('//') ? value : undefined; }
}
/** Public verification links cannot carry credentials or use ambiguous URL syntax. */
export function safeVerificationUrl(value?: string | null): string | undefined {
  if (!value || /[\\\u0000-\u0020]/.test(value)) return undefined;
  try {
    const url = new URL(value);
    return url.protocol === 'https:' && !url.username && !url.password ? value : undefined;
  } catch { return undefined; }
}
export function displayPrice(value: unknown, currency?: string): string {
  const amount = typeof value === 'number' ? value : Number(value);
  if (!Number.isFinite(amount)) return '';
  const locale = (window as any).G7Core?.state?.get?.()?.locale || document.documentElement.lang || undefined;
  try { return new Intl.NumberFormat(locale, currency ? {style:'currency',currency} : {}).format(amount); }
  catch { return new Intl.NumberFormat(undefined).format(amount); }
}

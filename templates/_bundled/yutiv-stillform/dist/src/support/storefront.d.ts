/** Presentation only. No order, reward or tenant calculations belong here. */
export interface StorefrontSlots {
    logoUrl?: string;
    bannerUrl?: string;
    selectedProductCodes?: readonly string[];
    rewardText?: string;
    /** Opaque server-issued reference. A future provider MUST validate it on the server. */
    orderContextReference?: string;
}
export declare function shopBase(): string;
export declare const placeholder: string;
/** Only same-origin or explicitly configured storage origins. Never forward tokens. */
export declare function safeMediaUrl(value?: string | null): string | undefined;
/** Public verification links cannot carry credentials or use ambiguous URL syntax. */
export declare function safeVerificationUrl(value?: string | null): string | undefined;
export declare function displayPrice(value: unknown, currency?: string): string;

export type CatalogForm = Record<string, any>;
export type TranslationItem = {
    id: string;
    field: string;
    source: string;
    current: string;
    locale: string;
    html: boolean;
    overwrite: boolean;
    status?: string;
    result?: string | null;
    error?: string | null;
};
export type TranslationBinding = TranslationItem & {
    locate: (form: CatalogForm, inputs: any[]) => {
        owner: any;
        key: string | number;
    }[];
};
export declare function collectTranslationBindings(form: CatalogForm, inputs: any[], kind: string, locales: string[], fields: string[], overwrite: boolean): TranslationBinding[];
export declare function sourceFingerprint(value: string): string;
export declare function staleTranslationFields(form: CatalogForm, inputs: any[], kind: string): string[];
/** Apply only reviewed text against latest input. Never generate combinations or replace selling units. */
export declare function applyTranslationResults(form: CatalogForm, inputs: any[], kind: string, bindings: TranslationBinding[], results: TranslationItem[]): {
    form: any;
    optionInputs: any;
    conflicts: string[];
    applied: number;
};

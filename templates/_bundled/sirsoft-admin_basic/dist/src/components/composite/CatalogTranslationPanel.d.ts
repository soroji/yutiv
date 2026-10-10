import { default as React } from 'react';
export interface CatalogTranslationPanelProps {
    kind?: 'product' | 'category';
    value?: Record<string, any>;
    optionInputs?: any[];
    entityId?: number | null;
    disabled?: boolean;
    configurationOnly?: boolean;
    defaultExpanded?: boolean;
    /** Engine custom event: flush pending input debounce before reading a snapshot. */
    onSnapshot?: () => void;
    __componentContext?: {
        state?: Record<string, any>;
        stateRef?: {
            current: Record<string, any>;
        };
    };
    onChange?: (event: {
        target: {
            value: {
                form: Record<string, any>;
                optionInputs: any[];
            };
        };
    }) => void;
}
export declare const CatalogTranslationPanel: React.FC<CatalogTranslationPanelProps>;

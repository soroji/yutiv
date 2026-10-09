import React, { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';
import userEvent from '@testing-library/user-event';
import { MultilingualInput } from '../../../../../../../templates/_bundled/sirsoft-admin_basic/src/components/composite/MultilingualInput';
import { initializeOptionInputsHandler, setOptionModeHandler, updateOptionInputHandler, generateOptionsHandler } from '../../handlers/optionHandlers';

let state: any;
let core: any;
beforeEach(() => {
    state = { form: { has_options: false, options: [], selling_price: 12000, list_price: 12000 }, ui: { optionInputs: [] } };
    core = {
        state: { getLocal: () => state, get: () => ({}), setLocal: (patch: any) => { state = { ...state, ...patch }; } },
        config: (key: string) => key === 'app.supported_locales' ? ['ko', 'en'] : 'ko',
        locale: { current: () => 'ko', supported: () => ['ko', 'en'] },
        t: (key: string) => key, toast: { error: vi.fn(), success: vi.fn(), warning: vi.fn() },
        modal: { open: vi.fn() },
    };
    (window as any).G7Core = core;
});
afterEach(cleanup);

describe('selectable option modes and multilingual input', () => {
    it('enables a new empty group and blocks a destructive mode change', () => {
        setOptionModeHandler({ params: { enabled: true } } as any);
        expect(state.form.has_options).toBe(true);
        expect(state.ui.optionInputs).toHaveLength(1);
        state.form.options = [{ id: 91, option_code: 'KEEP', stock_quantity: 8 }];
        setOptionModeHandler({ params: { enabled: false } } as any);
        expect(state.form.options[0].id).toBe(91);
        expect(state.form.has_options).toBe(true);
        expect(core.toast.error).toHaveBeenCalledOnce();
    });

    it('restores saved groups once without resetting edits or identifiers', () => {
        state.form.option_groups = [{ name: { ko: '색상', en: 'Color' }, values: [{ ko: '검정', en: 'Black' }, { ko: '흰색', en: 'White' }] }];
        state.form.options = [{ id: 91 }];
        initializeOptionInputsHandler();
        expect(state.ui.optionInputs[0].valueText).toEqual({ ko: '검정, 흰색', en: 'Black, White' });
        updateOptionInputHandler({ params: { index: 0, field: 'name', value: { ko: '새 색상' } } } as any, {} as any);
        initializeOptionInputsHandler();
        expect(state.ui.optionInputs[0].name.ko).toBe('새 색상');
        expect(state.form.options[0].id).toBe(91);
    });

    it.each(['name', 'valueText'])('keeps focused %s input through Korean composition and language switching', async (field) => {
        initializeOptionInputsHandler();
        const korean = field === 'name' ? '색상' : '검정, 흰색';
        const english = field === 'name' ? 'Color' : 'Black, White';
        function Editor() {
            const [, redraw] = useState(0);
            return <MultilingualInput name={field} layout="compact" value={state.ui.optionInputs[0][field]}
                onChange={(event: any) => {
                    updateOptionInputHandler({ params: { index: 0, field, value: event.target.value } } as any, {} as any);
                    redraw(n => n + 1);
                }} />;
        }
        render(<Editor />);
        const input = screen.getByRole('textbox') as HTMLInputElement;
        input.focus();
        fireEvent.compositionStart(input);
        fireEvent.change(input, { target: { value: '검' } });
        fireEvent.change(input, { target: { value: korean } });
        fireEvent.compositionEnd(input, { data: '색' });
        expect(screen.getByRole('textbox')).toBe(input);
        expect(document.activeElement).toBe(input);
        expect(input.value).toBe(korean);
        await userEvent.click(screen.getByRole('button', { name: 'EN' }));
        fireEvent.change(screen.getByRole('textbox'), { target: { value: english } });
        await userEvent.click(screen.getByRole('button', { name: /KO/ }));
        expect((screen.getByRole('textbox') as HTMLInputElement).value).toBe(korean);
        expect(state.ui.optionInputs[0][field]).toEqual({ ko: korean, en: english });
        if (field === 'valueText') {
            expect(state.ui.optionInputs[0].values).toEqual([{ ko: '검정', en: 'Black' }, { ko: '흰색', en: 'White' }]);
        }
    });

    it('generates multiple group combinations from the edited values', () => {
        state.ui.optionInputs = [
            { name: { ko: '색상', en: 'Color' }, values: [{ ko: '검정', en: 'Black' }, { ko: '흰색', en: 'White' }] },
            { name: { ko: '사이즈', en: 'Size' }, values: [{ ko: 'S', en: 'S' }, { ko: 'M', en: 'M' }, { ko: 'L', en: 'L' }] },
        ];
        generateOptionsHandler({ params: {} } as any, {} as any);
        expect(state.form.options).toHaveLength(6);
        expect(state.form.option_groups).toHaveLength(2);
    });

    it('keeps saved combination identifiers and stock when regenerating unchanged groups', () => {
        state.ui.optionInputs = [{ name: { ko: '색상', en: 'Color' }, values: [{ ko: '검정', en: 'Black' }, { ko: '흰색', en: 'White' }] }];
        generateOptionsHandler({ params: {} } as any, {} as any);
        state.form.options = state.form.options.map((option: any, index: number) => ({
            ...option, id: 90 + index, option_code: 'KEEP-' + index, sku: 'SKU-' + index,
            selling_price: 13000 + index, stock_quantity: 8 + index,
        }));
        generateOptionsHandler({ params: { skipConfirm: true } } as any, {} as any);
        expect(state.form.options.map((option: any) => [option.id, option.option_code, option.sku, option.selling_price, option.stock_quantity]))
            .toEqual([[90, 'KEEP-0', 'SKU-0', 13000, 8], [91, 'KEEP-1', 'SKU-1', 13001, 9]]);
    });
});

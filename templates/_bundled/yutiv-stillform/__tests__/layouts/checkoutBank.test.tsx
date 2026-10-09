import { describe, it, expect } from 'vitest';
import React from 'react';
import { fireEvent } from '@testing-library/react';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import * as basic from '../../src/components/basic';
import checkout from '../../layouts/shop/checkout.json';
import payment from '../../layouts/partials/shop/_checkout_payment.json';
import summary from '../../layouts/partials/shop/_checkout_summary.json';
import orderer from '../../layouts/partials/shop/_checkout_orderer.json';
import shipping from '../../layouts/partials/shop/_checkout_shipping.json';
import shop from '../../lang/partial/en/shop.json';

function nodes(root: any): any[] {
  return [root, ...(root.children ?? []).flatMap(nodes)];
}
function registry() {
  const Fragment = ({ children }: { children?: React.ReactNode }) => <>{children}</>;
  const r = ComponentRegistry.getInstance();
  (r as any).registry = Object.fromEntries(Object.entries({ ...basic, Fragment }).map(([name, component]) => [name, { component, metadata: { name, type: 'basic' } }]));
  return r;
}
const bank = { bank_code: 'TEST', account_number: 'fixture-only', account_holder: 'Fixture', is_active: true };
const submit = nodes(summary).find(n => n.props?.['data-testid'] === 'checkout-submit-order');
const dbank = payment.children.find(n => n.if === "{{_computed.selectedPaymentMethod === 'dbank'}}")!;
const bankSection = { ...dbank, children: dbank.children.slice(0, 3) };

describe('checkout bank availability (local rendering only)', () => {
  it.each([
    ['empty', [], null, 'dbank', true],
    ['inactive', [{ ...bank, is_active: false }], null, 'dbank', true],
    ['not selected', [bank], null, 'dbank', true],
    ['selected', [bank], bank, 'dbank', false],
    ['removed selection', [], bank, 'dbank', true],
    ['other method', [], null, 'card', false],
  ] as const)('%s: reflects availability without placing an order', async (_, accounts, selected, method, disabled) => {
    const local = { paymentMethod: method, selectedDbank: selected, shipping: { country_code: 'KR', zipcode: '12345', recipient_name: 'Fixture', recipient_phone: '01000000000' } };
    const settings = { data: { order_settings: { payment_methods: [{ id: method, is_active: true }], bank_accounts: accounts } } };
    const engine = new DataBindingEngine();
    const computed = Object.fromEntries(Object.entries(checkout.computed).filter(([k]) => !k.startsWith('_comment')).map(([k, v]) => [k, engine.evaluateExpression(v.slice(2, -2), { _local: local, _global: {}, paymentSettings: settings })]));
    const test = createLayoutTest({ components: [bankSection, submit] } as any, {
      componentRegistry: registry(), translations: { shop },
      initialState: { _local: { paymentMethod: method, selectedDbank: selected, shipping: { country_code: 'KR', zipcode: '12345', recipient_name: 'Fixture', recipient_phone: '01000000000' } } },
      initialData: { _computed: computed, paymentSettings: { data: { order_settings: { payment_methods: [{ id: method, is_active: true }], bank_accounts: accounts } } }, checkoutData: { data: { calculation: { summary: { final_amount: 20000, final_amount_formatted: '20,000' } } } } },
    });
    try {
      const { container } = await test.render();
      const button = container.querySelector('[data-testid="checkout-submit-order"]');
      expect(button).toBeInTheDocument();
      if (disabled) expect(button).toBeDisabled(); else expect(button).not.toBeDisabled();
      const unavailable = method === 'dbank' && !accounts.some(b => b.is_active);
      expect(!!container.querySelector('[data-testid="checkout-dbank-unavailable"]')).toBe(unavailable);
      if (unavailable) {
        expect(container.textContent).toContain(shop.checkout.dbank_unavailable);
        expect(container.textContent).not.toContain(shop.checkout.dbank_select_account);
      }
      
    } finally { test.cleanup(); }
  });

  it('renders orderer/shipping field errors and updates typed values without submitting', async () => {
    const fields = [...nodes(orderer), ...nodes(shipping)].filter(n => ['orderer_name', 'recipient_name'].includes(n.props?.name) || n.text === "{{_local.errors?.['orderer.name']?.[0] ?? ''}}" || n.text === "{{_local.errors?.['shipping.recipient_name']?.[0] ?? ''}}");
    const test = createLayoutTest({ components: fields, computed: checkout.computed } as any, {
      componentRegistry: registry(), translations: { shop },
      initialState: { _local: { orderer: { name: '' }, shipping: { recipient_name: '' }, errors: { 'orderer.name': ['Orderer name required'], 'shipping.recipient_name': ['Recipient name required'] } } },
    });
    try {
      const { container } = await test.render();
      expect(container.textContent).toContain('Orderer name required');
      expect(container.textContent).toContain('Recipient name required');
      const input = container.querySelector('input[name="orderer_name"]')!;
      expect(input).toHaveClass('border-red-500');
      fireEvent.change(input, { target: { value: '테스트 주문자' } });
      expect(input).toHaveValue('테스트 주문자');
      
    } finally { test.cleanup(); }
  });
});

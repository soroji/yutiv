import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { CompactNumberInput } from '../CompactNumberInput';

describe('CompactNumberInput', () => {
  it.each(['9999999999999.99', '2147483647'])('shows the complete %s and edits through the existing numeric change event', value => {
    const onChange = vi.fn();
    render(<CompactNumberInput value={value} onChange={onChange} min={0} step={100} max={value} label="Amount" />);
    const button = screen.getByRole('button');
    expect(button).toHaveTextContent(value);
    expect(button).toHaveStyle({ whiteSpace: 'nowrap', overflowWrap: 'normal', wordBreak: 'normal', textAlign: 'right' });
    fireEvent.click(button);
    const editor = screen.getByRole('spinbutton');
    expect(editor).toHaveFocus();
    expect(editor).toHaveValue(Number(value));
    expect(editor).toHaveAttribute('min', '0');
    expect(editor).toHaveAttribute('step', '100');
    expect(editor.closest('table')).toBeNull();
    fireEvent.change(editor, { target: { value: '1234' } });
    expect(onChange.mock.calls[0][0].target.value).toBe('1234');
    fireEvent.keyDown(document, { key: 'Escape' });
    expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument();
    expect(button).toHaveFocus();
    expect(button).toHaveTextContent('1234');
  });
  it('does not open an editor or emit a change when disabled', () => {
    const onChange = vi.fn();
    render(<CompactNumberInput value="2147483647" disabled onChange={onChange} />);
    expect(screen.getByRole('button')).toBeDisabled();
    fireEvent.click(screen.getByRole('button'));
    expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument();
    expect(onChange).not.toHaveBeenCalled();
  });
  it('keeps the 240px portal editor inside the viewport at its bottom-right edge (mocked geometry)', () => {
    const rect = vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue({ left: 1380, bottom: 898, top: 866, right: 1440, width: 60, height: 32, x: 1380, y: 866, toJSON: () => ({}) });
    vi.stubGlobal('innerWidth', 1440);
    vi.stubGlobal('innerHeight', 900);
    try {
      render(<CompactNumberInput value="10000" label="Price" />);
      fireEvent.click(screen.getByRole('button'));
      const portal = screen.getByRole('spinbutton').parentElement;
      expect(portal).toHaveStyle({ width: '240px', left: '1192px', top: '836px' });
      expect(portal?.closest('table')).toBeNull();
    } finally { rect.mockRestore(); vi.unstubAllGlobals(); }
  });
});

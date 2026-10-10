import React, { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Input, InputProps } from '../basic/Input';
import { Button } from '../basic/Button';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';

/** A single-line value with a full-size portal editor and an intrinsic width budget. */
export const CompactNumberInput: React.FC<InputProps> = ({ value, onChange, disabled, className = '', style: _style, label, ...props }) => {
  const [open, setOpen] = useState(false);
  const [position, setPosition] = useState({ top: 0, left: 0 });
  const trigger = useRef<HTMLButtonElement>(null);
  const [minimum, setMinimum] = useState(0);
  const editor = useRef<HTMLDivElement>(null);
  const input = useRef<HTMLInputElement>(null);
  const [draft, setDraft] = useState(value);
  useEffect(() => { setDraft(value); }, [value]);
  useLayoutEffect(() => {
    const measure = () => {
      // Include borders; the grid separately includes cell padding. The fallback is
      // conservative for 14px monospace before fonts/layout become available.
      const width = Math.ceil(Math.max(trigger.current?.querySelector('span')?.getBoundingClientRect().width ?? 0, String(draft ?? '').length * 8.5) + 4);
      setMinimum(width);
      if (trigger.current) {
        trigger.current.dataset.numericMinimum = String(width);
        trigger.current.dispatchEvent(new CustomEvent('g7:numeric-width', { bubbles: true }));
      }
    };
    measure();
    const observer = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(measure) : undefined;
    const text = trigger.current?.querySelector('span');
    if (text) observer?.observe(text);
    return () => observer?.disconnect();
  }, [draft]);
  useEffect(() => {
    if (!open) return;
    const place = () => {
      const rect = trigger.current?.getBoundingClientRect();
      if (rect) setPosition({ left: Math.max(8, Math.min(rect.left, window.innerWidth - 248)), top: Math.max(8, Math.min(rect.bottom + 4, window.innerHeight - 64)) });
    };
    const close = () => { setOpen(false); trigger.current?.focus(); };
    const outside = (event: MouseEvent) => { if (!editor.current?.contains(event.target as Node) && !trigger.current?.contains(event.target as Node)) setOpen(false); };
    const key = (event: KeyboardEvent) => { if (event.key === 'Escape' || event.key === 'Enter') { event.preventDefault(); close(); } };
    place(); input.current?.focus();
    document.addEventListener('mousedown', outside);
    document.addEventListener('keydown', key);
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
    return () => { document.removeEventListener('mousedown', outside); document.removeEventListener('keydown', key); window.removeEventListener('resize', place); window.removeEventListener('scroll', place, true); };
  }, [open]);
  return <Div className="w-full min-w-0">
    <Button ref={trigger} type="button" disabled={disabled} title={`${draft ?? ''}${props.title ? ` (${props.title})` : ''}`} aria-label={label ? `${label}: ${draft ?? ''}` : String(draft ?? '')} aria-expanded={open} onClick={event => { event.stopPropagation(); setOpen(!open); }} className={`w-full py-1 border rounded text-right text-sm bg-white dark:bg-gray-800 disabled:opacity-50 ${className}`} style={{ width: '100%', minWidth: minimum, display: 'block', textAlign: 'right', paddingLeft: 0, paddingRight: 0, fontFamily: 'monospace', fontSize: 14, whiteSpace: 'nowrap', overflowWrap: 'normal', wordBreak: 'normal' }}><Span style={{ display: 'inline-block', whiteSpace: 'nowrap' }}>{String(draft ?? '')}</Span></Button>
    {open && createPortal(<Div ref={editor} className="fixed z-[9999] p-2 bg-white dark:bg-gray-800 border rounded shadow-lg" style={{ ...position, width: '240px', maxWidth: 'calc(100vw - 16px)' }}>
      <Input ref={input} {...props} type="number" value={draft} disabled={disabled} aria-label={label} onBlur={event => { props.onBlur?.(event); if (!editor.current?.contains(event.relatedTarget as Node)) setOpen(false); }} onChange={event => { setDraft(event.target.value); onChange?.(event); }} className="w-full min-w-0 text-right font-mono text-sm" />
    </Div>, document.body)}
  </Div>;
};

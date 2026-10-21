import { useEffect, useRef } from 'react';

const FOCUSABLE = 'a[href], button, input:not([type="hidden"]), select, textarea, [tabindex]:not([tabindex="-1"])';

/**
 * Accessible modal behaviour for the hand-built dialogs: spread the result on the dialog's outer element.
 *
 * - announces it as a modal dialog named by its first heading;
 * - moves focus into it (first form field, else first control) and keeps Tab inside it;
 * - Escape closes it the same way its close button does, so a close button disabled while saving or uploading also
 *   blocks Escape;
 * - returns focus to whatever opened it.
 */
export function useDialog<T extends HTMLElement = HTMLDivElement>(isOpen: boolean, onClose: () => void) {
  const ref = useRef<T>(null);
  const closeRef = useRef(onClose);
  closeRef.current = onClose;

  useEffect(() => {
    const node = ref.current;
    if (!isOpen || !node) return undefined;
    const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;

    if (!node.hasAttribute('aria-labelledby') && !node.hasAttribute('aria-label')) {
      const heading = node.querySelector<HTMLElement>('h1, h2, h3');
      if (heading) {
        if (!heading.id) heading.id = `dialog-title-${Math.random().toString(36).slice(2, 9)}`;
        node.setAttribute('aria-labelledby', heading.id);
      }
    }

    const focusables = () => Array.from(node.querySelectorAll<HTMLElement>(FOCUSABLE))
      .filter((el) => !el.hasAttribute('disabled') && !el.closest('[hidden], [aria-hidden="true"]'));
    const field = node.querySelector<HTMLElement>('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])');
    (field ?? focusables()[0] ?? node).focus({ preventScroll: true });

    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        const close = node.querySelector<HTMLButtonElement>('button[aria-label="Close"]');
        if (close) {
          if (!close.disabled) close.click();
        } else {
          closeRef.current();
        }
      } else if (e.key === 'Tab') {
        const items = focusables();
        if (items.length === 0) return;
        const first = items[0];
        const last = items[items.length - 1];
        if (e.shiftKey && (document.activeElement === first || !node.contains(document.activeElement))) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && (document.activeElement === last || !node.contains(document.activeElement))) {
          e.preventDefault();
          first.focus();
        }
      }
    };
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('keydown', onKey);
      if (opener && document.contains(opener)) opener.focus({ preventScroll: true });
    };
  }, [isOpen]);

  return { ref, role: 'dialog' as const, 'aria-modal': true as const, tabIndex: -1 };
}

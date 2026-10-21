import React, { useState } from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { useDialog } from '@/hooks/useDialog';

const Modal: React.FC<{ isOpen: boolean; onClose: () => void; busy?: boolean }> = ({ isOpen, onClose, busy = false }) => {
  const dialog = useDialog(isOpen, onClose);
  if (!isOpen) return null;
  return (
    <div className="fixed inset-0" {...dialog}>
      <h2>Add Course</h2>
      <input aria-label="Course code" />
      <button aria-label="Close" onClick={onClose} disabled={busy}>x</button>
    </div>
  );
};

const Page: React.FC<{ busy?: boolean; onClose?: () => void }> = ({ busy, onClose }) => {
  const [open, setOpen] = useState(false);
  return (
    <>
      <button onClick={() => setOpen(true)}>Add Course</button>
      <Modal isOpen={open} busy={busy} onClose={() => { onClose?.(); setOpen(false); }} />
    </>
  );
};

describe('useDialog', () => {
  it('is a labelled modal dialog that takes focus, closes on Escape and gives focus back', () => {
    render(<Page />);
    const opener = screen.getByRole('button', { name: 'Add Course' });
    opener.focus();
    fireEvent.click(opener);

    const dialog = screen.getByRole('dialog', { name: 'Add Course' });
    expect(dialog).toHaveAttribute('aria-modal', 'true');
    expect(screen.getByLabelText('Course code')).toHaveFocus();

    fireEvent.keyDown(document, { key: 'Escape' });
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(opener).toHaveFocus();
  });

  it('keeps Tab inside the dialog', () => {
    render(<Page />);
    fireEvent.click(screen.getByRole('button', { name: 'Add Course' }));
    const close = screen.getByRole('button', { name: 'Close' });
    close.focus();
    fireEvent.keyDown(document, { key: 'Tab' });
    expect(screen.getByLabelText('Course code')).toHaveFocus();
    fireEvent.keyDown(document, { key: 'Tab', shiftKey: true });
    expect(close).toHaveFocus();
  });

  it('does not close on Escape while its close button is disabled (e.g. during an upload)', () => {
    const onClose = vi.fn();
    render(<Page busy onClose={onClose} />);
    fireEvent.click(screen.getByRole('button', { name: 'Add Course' }));
    fireEvent.keyDown(document, { key: 'Escape' });
    expect(onClose).not.toHaveBeenCalled();
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });
});

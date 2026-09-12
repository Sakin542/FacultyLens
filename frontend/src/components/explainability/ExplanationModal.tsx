import React, { useEffect } from 'react';
import { ExplanationPanel, ExplanationPanelProps } from './ExplanationPanel';

interface ExplanationModalProps extends Omit<ExplanationPanelProps, 'onClose'> {
  isOpen: boolean;
  onClose: () => void;
}

/** Modal wrapper around ExplanationPanel (Escape closes; focus trapped to the dialog by role/aria-modal). */
export const ExplanationModal: React.FC<ExplanationModalProps> = ({ isOpen, onClose, ...panelProps }) => {
  useEffect(() => {
    if (!isOpen) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-[100] flex items-start sm:items-center justify-center bg-sage-800/40 backdrop-blur-[2px] p-4 overflow-y-auto" role="presentation" onClick={onClose}>
      <div role="dialog" aria-modal="true" aria-label={panelProps.title ?? 'AI explanation'} className="w-full max-w-2xl my-4" onClick={(e) => e.stopPropagation()} data-testid="explanation-modal">
        <ExplanationPanel {...panelProps} onClose={onClose} className="shadow-xl max-h-[85vh] overflow-y-auto" />
      </div>
    </div>
  );
};

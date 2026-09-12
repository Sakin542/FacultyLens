import { useCallback, useState } from 'react';
import { ExplanationModal } from '@/components/explainability/ExplanationModal';
import type { AiExplanation, AiResultType, ExplanationEvidence } from '@/types/explainability';

interface Target {
  type: AiResultType;
  id: number | string;
  title?: string;
}

interface Options {
  onChanged?: (explanation: AiExplanation) => void;
  onOpenSource?: (item: ExplanationEvidence) => void;
}

/**
 * STEP 45: one modal per page; any AI badge/“Why?” button calls `explain(type, id, title)`.
 * The explanation is fetched only when opened (lazy), never for every result on the page.
 */
export function useExplanationModal(options: Options = {}) {
  const [target, setTarget] = useState<Target | null>(null);

  const explain = useCallback((type: AiResultType, id: number | string, title?: string) => {
    setTarget({ type, id, title });
  }, []);

  const close = useCallback(() => setTarget(null), []);

  const modal = target ? (
    <ExplanationModal isOpen type={target.type} id={target.id} title={target.title} onClose={close} onChanged={options.onChanged} onOpenSource={options.onOpenSource} />
  ) : null;

  return { explain, close, modal, target };
}

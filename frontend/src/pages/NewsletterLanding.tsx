import React, { useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { AlertCircle, CheckCircle2, Loader2, Mail, MailX } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { newsletterService } from '@/services/newsletterService';

type Mode = 'confirm' | 'unsubscribe';
type State = { kind: 'working' } | { kind: 'done'; message: string } | { kind: 'error'; message: string };

const COPY: Record<Mode, { title: string; working: string; fallback: string }> = {
  confirm: { title: 'Confirm your subscription', working: 'Confirming your subscription…', fallback: 'Your subscription is confirmed.' },
  unsubscribe: { title: 'Unsubscribe', working: 'Updating your preferences…', fallback: 'You have been unsubscribed.' },
};

/**
 * Landing page for the two token links in Faculty dispatch e-mails. The token is consumed with a POST so that
 * mail scanners pre-fetching the URL (GET) cannot confirm or unsubscribe on the reader's behalf.
 */
export const NewsletterLanding: React.FC<{ mode: Mode }> = ({ mode }) => {
  const { token = '' } = useParams<{ token: string }>();
  const [state, setState] = useState<State>({ kind: 'working' });
  const started = useRef(false);

  useEffect(() => {
    if (started.current) return;
    started.current = true;
    const run = mode === 'confirm' ? newsletterService.confirm : newsletterService.unsubscribe;
    run(token)
      .then((res) => setState({ kind: 'done', message: res.message || COPY[mode].fallback }))
      .catch((err: unknown) => setState({ kind: 'error', message: err instanceof Error && err.message ? err.message : 'This link is not valid.' }));
  }, [mode, token]);

  const Icon = mode === 'confirm' ? Mail : MailX;

  return (
    <div className="min-h-[60vh] flex items-center justify-center p-6" data-testid={`newsletter-${mode}`}>
      <div className="w-full max-w-lg rounded-xl border border-sage-200 bg-white p-6 space-y-4">
        <h1 className="text-xl font-bold text-sage-800 flex items-center gap-2"><Icon className="w-5 h-5" aria-hidden="true" /> {COPY[mode].title}</h1>
        <p className="text-xs text-sage-500">Faculty dispatch — occasional notes on assessment design and AI governance.</p>

        {state.kind === 'working' && (
          <p role="status" className="flex items-center gap-2 text-sm text-sage-600"><Loader2 className="w-4 h-4 animate-spin" aria-hidden="true" />{COPY[mode].working}</p>
        )}
        {state.kind === 'done' && (
          <p role="status" className="flex items-start gap-2 rounded-lg border border-[#BBF7D0] bg-[#F0FDF4] px-3 py-2 text-sm text-[#166534]" data-testid="newsletter-result"><CheckCircle2 className="w-4 h-4 mt-0.5 shrink-0" aria-hidden="true" />{state.message}</p>
        )}
        {state.kind === 'error' && (
          <p role="alert" className="flex items-start gap-2 rounded-lg border border-[#FECACA] bg-[#FEF2F2] px-3 py-2 text-sm text-[#991B1B]" data-testid="newsletter-result"><AlertCircle className="w-4 h-4 mt-0.5 shrink-0" aria-hidden="true" />{state.message}</p>
        )}

        <div className="flex flex-wrap gap-2 pt-1">
          <Link to="/"><Button size="sm" variant="outline">Back to FacultyLens</Button></Link>
          {state.kind === 'error' && mode === 'confirm' && <Link to="/#footer-email"><Button size="sm">Subscribe again</Button></Link>}
        </div>
      </div>
    </div>
  );
};

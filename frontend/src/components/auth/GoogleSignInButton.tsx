import React, { useEffect, useRef, useState } from 'react';

interface GoogleCredentialResponse { credential?: string }

interface GoogleAccountsId {
  initialize: (options: { client_id: string; callback: (r: GoogleCredentialResponse) => void; ux_mode?: string; auto_select?: boolean }) => void;
  renderButton: (el: HTMLElement, options: Record<string, unknown>) => void;
}

declare global {
  interface Window { google?: { accounts?: { id?: GoogleAccountsId } } }
}

const GIS_SRC = 'https://accounts.google.com/gsi/client';
let gisLoader: Promise<void> | null = null;

function loadGis(): Promise<void> {
  if (window.google?.accounts?.id) return Promise.resolve();
  if (!gisLoader) {
    gisLoader = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = GIS_SRC;
      script.async = true;
      script.defer = true;
      script.onload = () => resolve();
      script.onerror = () => { gisLoader = null; reject(new Error('Google sign-in could not be loaded.')); };
      document.head.appendChild(script);
    });
  }
  return gisLoader;
}

interface Props {
  clientId: string;
  onCredential: (credential: string) => void;
  onError?: (message: string) => void;
}

/**
 * Official Google Identity Services button. Google renders it inside an iframe and hands back an ID token,
 * which the backend verifies (audience, issuer, expiry, verified e-mail, allowed domains).
 */
export const GoogleSignInButton: React.FC<Props> = ({ clientId, onCredential, onError }) => {
  const ref = useRef<HTMLDivElement>(null);
  const [failed, setFailed] = useState(false);
  const callback = useRef(onCredential);
  callback.current = onCredential;

  useEffect(() => {
    let cancelled = false;
    loadGis()
      .then(() => {
        const gis = window.google?.accounts?.id;
        if (cancelled || !gis || !ref.current) return;
        gis.initialize({
          client_id: clientId,
          callback: (r) => { if (r.credential) callback.current(r.credential); },
        });
        gis.renderButton(ref.current, { theme: 'outline', size: 'large', text: 'signin_with', shape: 'rectangular', width: ref.current.offsetWidth || 320 });
      })
      .catch((e: Error) => { if (!cancelled) { setFailed(true); onError?.(e.message); } });
    return () => { cancelled = true; };
  }, [clientId, onError]);

  if (failed) return null;

  return (
    <div className="space-y-3" data-testid="google-sign-in">
      <div className="flex items-center gap-3 text-[11px] uppercase tracking-[0.16em] text-sage-400">
        <span className="h-px flex-1 bg-sage-200" />or<span className="h-px flex-1 bg-sage-200" />
      </div>
      <div ref={ref} className="flex justify-center min-h-[44px]" />
    </div>
  );
};

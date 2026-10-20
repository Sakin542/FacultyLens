import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiError } from '@/services/api';

export interface AsyncState<T> {
  data: T | null;
  error: ApiError | Error | null;
  loading: boolean;
  /** Re-run the loader; existing data stays on screen while it refreshes. */
  reload: () => Promise<void>;
  setData: React.Dispatch<React.SetStateAction<T | null>>;
}

/** Load data for an admin page. Stale responses (from an older set of deps) are ignored. */
export function useAdminData<T>(loader: () => Promise<T>, deps: React.DependencyList): AsyncState<T> {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<ApiError | Error | null>(null);
  const [loading, setLoading] = useState(true);
  const seq = useRef(0);

  // eslint-disable-next-line react-hooks/exhaustive-deps
  const run = useCallback(loader, deps);

  const reload = useCallback(async () => {
    const id = ++seq.current;
    setLoading(true);
    try {
      const result = await run();
      if (id === seq.current) {
        setData(result);
        setError(null);
      }
    } catch (e) {
      if (id === seq.current) setError(e instanceof Error ? e : new Error(String(e)));
    } finally {
      if (id === seq.current) setLoading(false);
    }
  }, [run]);

  useEffect(() => { void reload(); }, [reload]);

  return { data, error, loading, reload, setData };
}

/** Run a mutation and report its message; errors become a message too, never an unhandled rejection. */
export function useAction() {
  const [busy, setBusy] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ tone: 'success' | 'error'; text: string } | null>(null);

  const run = useCallback(async <R,>(key: string, fn: () => Promise<R & { message?: string }>, fallback = 'Done.'): Promise<R | null> => {
    setBusy(key);
    setNotice(null);
    try {
      const result = await fn();
      setNotice({ tone: 'success', text: result?.message || fallback });
      return result;
    } catch (e) {
      setNotice({ tone: 'error', text: e instanceof Error ? e.message : 'Something went wrong.' });
      return null;
    } finally {
      setBusy(null);
    }
  }, []);

  return { busy, notice, setNotice, run };
}

export function useDebounced<T>(value: T, ms = 300): T {
  const [v, setV] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setV(value), ms);
    return () => clearTimeout(t);
  }, [value, ms]);
  return v;
}

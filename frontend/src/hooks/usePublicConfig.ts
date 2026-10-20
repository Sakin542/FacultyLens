import { useEffect, useState } from 'react';
import { configService, DEFAULT_PUBLIC_CONFIG, PublicConfig } from '@/services/configService';

/** Feature toggles + Google client id for the current visitor (defaults until the first response arrives). */
export function usePublicConfig(): PublicConfig {
  const [config, setConfig] = useState<PublicConfig>(DEFAULT_PUBLIC_CONFIG);

  useEffect(() => {
    let active = true;
    configService.get().then((c) => { if (active) setConfig(c); });
    return () => { active = false; };
  }, []);

  return config;
}

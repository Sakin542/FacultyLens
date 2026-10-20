import { apiClient } from './api';

export interface PublicFeatures {
  registration: boolean;
  google_sign_in: boolean;
  ai_analysis: boolean;
  academic_chat: boolean;
  question_generation: boolean;
  ai_grading: boolean;
  announcement: string;
}

export interface PublicConfig {
  features: PublicFeatures;
  /** Google OAuth web client id; null when Google sign-in is not configured or switched off. */
  google_client_id: string | null;
}

export const DEFAULT_PUBLIC_CONFIG: PublicConfig = {
  features: {
    registration: true,
    google_sign_in: true,
    ai_analysis: true,
    academic_chat: true,
    question_generation: true,
    ai_grading: true,
    announcement: '',
  },
  google_client_id: null,
};

let cached: Promise<PublicConfig> | null = null;

export const configService = {
  /** Anonymous-safe; shared by every caller for the page lifetime (call refresh() after an admin change). */
  get: (): Promise<PublicConfig> => {
    if (!cached) {
      cached = apiClient<{ data: PublicConfig }>('/config')
        .then((res) => ({ ...DEFAULT_PUBLIC_CONFIG, ...res.data, features: { ...DEFAULT_PUBLIC_CONFIG.features, ...res.data?.features } }))
        .catch(() => {
          cached = null;
          return DEFAULT_PUBLIC_CONFIG;
        });
    }
    return cached;
  },
  refresh: (): Promise<PublicConfig> => {
    cached = null;
    return configService.get();
  },
};

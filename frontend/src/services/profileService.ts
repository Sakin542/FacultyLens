import { apiClient, getCsrfCookie, BACKEND_URL } from './api';
import { authService, AuthResponse, UpdateProfilePayload } from './authService';
import { User } from '@/types';

/** Mirrors backend config/profile_picture.php — the server remains authoritative. */
export const PROFILE_PICTURE_MAX_SIZE_MB = 5;
export const PROFILE_PICTURE_MAX_SIZE_BYTES = PROFILE_PICTURE_MAX_SIZE_MB * 1024 * 1024;
export const PROFILE_PICTURE_ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp'] as const;
export const PROFILE_PICTURE_ACCEPT_ATTR = '.jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp';

export const PROFILE_PICTURE_TYPE_ERROR = 'Please select a JPG, PNG, or WEBP image.';
export const PROFILE_PICTURE_SIZE_ERROR = `Profile picture must be ${PROFILE_PICTURE_MAX_SIZE_MB} MB or smaller.`;

export interface ProfilePictureResponse {
  status: 'success' | 'error';
  message?: string;
  user?: User;
}

/**
 * Client-side pre-check so the user gets instant feedback. Never a substitute for server validation.
 */
export function validateProfilePictureFile(file: File): string | null {
  const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
  const typeOk = (PROFILE_PICTURE_ACCEPTED_TYPES as readonly string[]).includes(file.type)
    || (file.type === '' && ['jpg', 'jpeg', 'png', 'webp'].includes(ext));
  if (!typeOk) return PROFILE_PICTURE_TYPE_ERROR;
  if (file.size > PROFILE_PICTURE_MAX_SIZE_BYTES) return PROFILE_PICTURE_SIZE_ERROR;
  if (file.size === 0) return 'The selected file is empty.';
  return null;
}

/**
 * Turns the relative `/api/...` URL returned by the backend into an absolute URL for <img src>.
 * The version query parameter added by the server busts browser caches on replace/remove.
 */
export function resolveProfilePictureUrl(url: string | null | undefined): string | null {
  if (!url) return null;
  if (/^https?:\/\//i.test(url)) return url;
  return `${BACKEND_URL}${url.startsWith('/') ? url : `/${url}`}`;
}

export const profileService = {
  getProfile: (): Promise<AuthResponse> => authService.getCurrentUser(),

  updateProfile: (data: UpdateProfilePayload): Promise<AuthResponse> => authService.updateProfile(data),

  uploadProfilePicture: async (file: File, options: { signal?: AbortSignal } = {}): Promise<ProfilePictureResponse> => {
    await getCsrfCookie();
    const body = new FormData();
    body.append('profile_picture', file, file.name);
    return apiClient<ProfilePictureResponse>('/profile/picture', { method: 'POST', body, signal: options.signal });
  },

  removeProfilePicture: async (): Promise<ProfilePictureResponse> => {
    await getCsrfCookie();
    return apiClient<ProfilePictureResponse>('/profile/picture', { method: 'DELETE' });
  },

  getProfilePictureUrl: (user: Pick<User, 'profile_picture_url'> | null | undefined): string | null =>
    resolveProfilePictureUrl(user?.profile_picture_url ?? null),
};

import React, { useEffect, useState } from 'react';
import { User as UserIcon } from 'lucide-react';
import { cn } from '@/utils/cn';
import { resolveProfilePictureUrl } from '@/services/profileService';

export type ProfilePictureSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl' | '2xl';

const SIZE_CLASSES: Record<ProfilePictureSize, string> = {
  xs: 'w-6 h-6 text-[10px]',
  sm: 'w-8 h-8 text-[11px]',
  md: 'w-9 h-9 text-sm',
  lg: 'w-12 h-12 text-base',
  xl: 'w-16 h-16 text-xl',
  '2xl': 'w-24 h-24 sm:w-28 sm:h-28 text-3xl',
};

const ICON_CLASSES: Record<ProfilePictureSize, string> = {
  xs: 'w-3 h-3',
  sm: 'w-4 h-4',
  md: 'w-4 h-4',
  lg: 'w-5 h-5',
  xl: 'w-7 h-7',
  '2xl': 'w-10 h-10',
};

export type ProfilePictureTone = 'light' | 'dark' | 'inverse';

const TONE_CLASSES: Record<ProfilePictureTone, string> = {
  light: 'bg-sage-100 text-sage-800 border border-sage-200',
  dark: 'bg-sage-700 text-white',
  inverse: 'bg-white text-sage-700',
};

export interface ProfilePictureProps {
  /** Relative (`/api/...`) or absolute image URL; null/undefined renders the fallback. */
  src?: string | null;
  /** Display name used for the initial fallback and accessible label. */
  name?: string | null;
  size?: ProfilePictureSize;
  tone?: ProfilePictureTone;
  className?: string;
  /** When the name is rendered right next to the avatar, mark the image decorative. */
  decorative?: boolean;
  /** Overrides the accessible label (defaults to "Profile picture of {name}"). */
  alt?: string;
  /** Optional local preview (object URL) that takes precedence over `src`. */
  previewSrc?: string | null;
}

/** Fallback: Profile Picture → Initial → generic user icon. */
export const initialFor = (name?: string | null): string => {
  const first = (name ?? '').trim().split(/\s+/).filter(Boolean)[0] ?? '';
  const ch = first.charAt(0);
  return ch ? ch.toUpperCase() : '';
};

export const ProfilePicture: React.FC<ProfilePictureProps> = ({
  src,
  name,
  size = 'md',
  tone = 'light',
  className,
  decorative = false,
  alt,
  previewSrc,
}) => {
  const resolved = previewSrc ?? resolveProfilePictureUrl(src);
  const [failedSrc, setFailedSrc] = useState<string | null>(null);

  // A new URL (new version) gets a fresh chance even if a previous one failed.
  useEffect(() => { setFailedSrc(null); }, [resolved]);

  const showImage = Boolean(resolved) && failedSrc !== resolved;
  const initial = initialFor(name);
  const label = alt ?? (name ? `Profile picture of ${name}` : 'Profile picture');
  const base = cn(
    'relative inline-flex items-center justify-center rounded-full overflow-hidden shrink-0 font-semibold select-none',
    SIZE_CLASSES[size],
    className,
  );

  if (showImage && resolved) {
    return (
      <span className={cn(base, 'bg-sage-100')} data-testid="profile-picture" data-state="image">
        <img
          src={resolved}
          alt={decorative ? '' : label}
          aria-hidden={decorative || undefined}
          className="w-full h-full object-cover"
          loading="lazy"
          decoding="async"
          draggable={false}
          onError={() => setFailedSrc(resolved)}
        />
      </span>
    );
  }

  return (
    <span
      className={cn(base, TONE_CLASSES[tone])}
      role={decorative ? undefined : 'img'}
      aria-label={decorative ? undefined : label}
      aria-hidden={decorative || undefined}
      data-testid="profile-picture"
      data-state={initial ? 'initial' : 'icon'}
    >
      {initial ? initial : <UserIcon className={ICON_CLASSES[size]} aria-hidden="true" />}
    </span>
  );
};

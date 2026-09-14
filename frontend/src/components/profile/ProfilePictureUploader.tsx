import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Camera, Trash2, Upload, X, CheckCircle2, AlertCircle } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { ProfilePicture } from './ProfilePicture';
import { ApiError } from '@/services/api';
import {
  profileService,
  validateProfilePictureFile,
  PROFILE_PICTURE_ACCEPT_ATTR,
  PROFILE_PICTURE_MAX_SIZE_MB,
} from '@/services/profileService';
import { User } from '@/types';

export interface ProfilePictureUploaderProps {
  user: User | null;
  /** Called with the fresh user payload after a successful upload or removal. */
  onUpdated: (user: User) => void;
  className?: string;
}

const GENERIC_ERROR = 'Unable to update your profile picture. Please try again.';

const messageFromError = (err: unknown): string => {
  if (err instanceof ApiError) {
    const field = err.errors?.profile_picture?.[0];
    if (field) return field;
    if (err.status === 413) return `Profile picture must be ${PROFILE_PICTURE_MAX_SIZE_MB} MB or smaller.`;
    if (err.status === 401 || err.status === 419) return 'Your session has expired. Please sign in again.';
    return err.message || GENERIC_ERROR;
  }
  return err instanceof Error && err.message ? err.message : GENERIC_ERROR;
};

const createPreviewUrl = (file: File): string | null => {
  try {
    return typeof URL !== 'undefined' && typeof URL.createObjectURL === 'function' ? URL.createObjectURL(file) : null;
  } catch {
    return null; // preview is a nicety; upload still works without it
  }
};

const revokePreviewUrl = (url: string | null) => {
  try {
    if (url && typeof URL !== 'undefined' && typeof URL.revokeObjectURL === 'function') URL.revokeObjectURL(url);
  } catch {
    /* ignore */
  }
};

/**
 * Select → validate → preview → confirm → upload. Nothing is sent until the user clicks "Save photo".
 */
export const ProfilePictureUploader: React.FC<ProfilePictureUploaderProps> = ({ user, onUpdated, className }) => {
  const inputRef = useRef<HTMLInputElement>(null);
  const abortRef = useRef<AbortController | null>(null);

  const [pending, setPending] = useState<File | null>(null);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const [busy, setBusy] = useState<'upload' | 'remove' | null>(null);
  const [confirmRemove, setConfirmRemove] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  const displayName = user?.name || user?.fullName || '';
  const hasPicture = Boolean(user?.profile_picture_url);

  const clearPending = useCallback(() => {
    setPending(null);
    setPreviewUrl(null);
    if (inputRef.current) inputRef.current.value = '';
  }, []);

  // Revoke the previous object URL whenever it is replaced or the component unmounts.
  useEffect(() => () => revokePreviewUrl(previewUrl), [previewUrl]);
  useEffect(() => () => abortRef.current?.abort(), []);

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    setSuccess(null);
    setError(null);
    setConfirmRemove(false);
    if (!file) return;

    const validationError = validateProfilePictureFile(file);
    if (validationError) {
      setError(validationError);
      clearPending();
      return;
    }
    setPending(file);
    setPreviewUrl(createPreviewUrl(file));
  };

  const handleUpload = async () => {
    if (!pending || busy) return;
    setBusy('upload');
    setError(null);
    setSuccess(null);
    const controller = new AbortController();
    abortRef.current = controller;
    try {
      const response = await profileService.uploadProfilePicture(pending, { signal: controller.signal });
      if (response.user) onUpdated(response.user);
      setSuccess(response.message || 'Profile picture updated successfully.');
      clearPending();
    } catch (err) {
      if (!(err instanceof DOMException && err.name === 'AbortError')) setError(messageFromError(err));
    } finally {
      abortRef.current = null;
      setBusy(null);
    }
  };

  const handleRemove = async () => {
    if (busy) return;
    setBusy('remove');
    setError(null);
    setSuccess(null);
    try {
      const response = await profileService.removeProfilePicture();
      if (response.user) onUpdated(response.user);
      setSuccess(response.message || 'Profile picture removed.');
      setConfirmRemove(false);
      clearPending();
    } catch (err) {
      setError(messageFromError(err));
    } finally {
      setBusy(null);
    }
  };

  const openPicker = () => {
    setSuccess(null);
    setError(null);
    inputRef.current?.click();
  };

  return (
    <div className={className} data-testid="profile-picture-uploader">
      <div className="flex flex-col sm:flex-row sm:items-center gap-4 sm:gap-6">
        <ProfilePicture
          src={user?.profile_picture_url}
          previewSrc={previewUrl}
          name={displayName}
          size="2xl"
          tone="dark"
          alt={pending ? 'Preview of the selected profile picture' : undefined}
          className="ring-4 ring-sage-100 shadow-subtle"
        />

        <div className="min-w-0 flex-1 space-y-3">
          <div>
            <p className="text-sm font-semibold text-sage-800">Profile photo</p>
            <p className="text-xs text-sage-500">
              JPG, PNG or WEBP, up to {PROFILE_PICTURE_MAX_SIZE_MB} MB. Your photo is stored privately and shown to collaborators on shared courses.
            </p>
          </div>

          <input
            ref={inputRef}
            type="file"
            accept={PROFILE_PICTURE_ACCEPT_ATTR}
            className="sr-only"
            tabIndex={-1}
            aria-label="Profile picture file"
            data-testid="profile-picture-input"
            onChange={handleFileChange}
            disabled={Boolean(busy)}
          />

          {pending ? (
            <div className="flex flex-wrap items-center gap-2" data-testid="profile-picture-pending">
              <Button type="button" size="sm" variant="primary" isLoading={busy === 'upload'} onClick={handleUpload} leftIcon={<Upload className="w-4 h-4" />} aria-label="Save new profile picture">
                {busy === 'upload' ? 'Uploading…' : 'Save photo'}
              </Button>
              <Button type="button" size="sm" variant="outline" onClick={clearPending} disabled={Boolean(busy)} leftIcon={<X className="w-4 h-4" />}>
                Cancel
              </Button>
              <span className="text-xs text-sage-500 truncate max-w-[14rem]" title={pending.name}>{pending.name}</span>
            </div>
          ) : confirmRemove ? (
            <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Confirm removing profile picture" data-testid="profile-picture-confirm-remove">
              <span className="text-xs text-sage-700">Remove your profile picture?</span>
              <Button type="button" size="sm" variant="danger" isLoading={busy === 'remove'} onClick={handleRemove} aria-label="Confirm remove profile picture">
                {busy === 'remove' ? 'Removing…' : 'Remove'}
              </Button>
              <Button type="button" size="sm" variant="ghost" onClick={() => setConfirmRemove(false)} disabled={Boolean(busy)}>
                Keep
              </Button>
            </div>
          ) : (
            <div className="flex flex-wrap items-center gap-2">
              <Button type="button" size="sm" variant="outline" onClick={openPicker} disabled={Boolean(busy)} leftIcon={<Camera className="w-4 h-4" />} aria-label="Change profile picture">
                {hasPicture ? 'Change photo' : 'Upload photo'}
              </Button>
              {hasPicture && (
                <Button type="button" size="sm" variant="ghost" className="text-[#991B1B]" onClick={() => setConfirmRemove(true)} disabled={Boolean(busy)} leftIcon={<Trash2 className="w-4 h-4" />} aria-label="Remove profile picture">
                  Remove
                </Button>
              )}
            </div>
          )}

          {success && (
            <p role="status" className="text-xs text-[#166534] font-medium flex items-center gap-1.5">
              <CheckCircle2 className="w-4 h-4 shrink-0" aria-hidden="true" />{success}
            </p>
          )}
          {error && (
            <p role="alert" className="text-xs text-[#991B1B] font-medium flex items-center gap-1.5">
              <AlertCircle className="w-4 h-4 shrink-0" aria-hidden="true" />{error}
            </p>
          )}
        </div>
      </div>
    </div>
  );
};

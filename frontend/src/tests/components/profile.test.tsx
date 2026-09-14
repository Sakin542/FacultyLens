import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ProfilePicture, initialFor } from '@/components/profile/ProfilePicture';
import { ProfilePictureUploader } from '@/components/profile/ProfilePictureUploader';
import { Sidebar } from '@/components/layout/Sidebar';
import { Settings } from '@/pages/Settings';
import { AuthContext } from '@/context/AuthContext';
import { ApiError, BACKEND_URL } from '@/services/api';
import { profileService, validateProfilePictureFile, resolveProfilePictureUrl, PROFILE_PICTURE_SIZE_ERROR, PROFILE_PICTURE_TYPE_ERROR } from '@/services/profileService';
import { User } from '@/types';

vi.mock('@/services/profileService', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/profileService')>();
  return {
    ...actual,
    profileService: {
      ...actual.profileService,
      uploadProfilePicture: vi.fn(),
      removeProfilePicture: vi.fn(),
    },
  };
});

const baseUser: User = {
  id: 7,
  name: 'Sakin Rahman',
  email: 'sakin@example.edu',
  department: 'CSE',
  designation: 'Lecturer',
  profile_picture_url: null,
};

const pictureUser: User = { ...baseUser, profile_picture_url: '/api/users/7/profile-picture?v=100' };

const png = (name = 'avatar.png', type = 'image/png', size = 1024): File => {
  const file = new File([new Uint8Array(size)], name, { type });
  return file;
};

describe('ProfilePicture', () => {
  it('renders the image when a URL is present and resolves it against the backend', () => {
    render(<ProfilePicture src="/api/users/7/profile-picture?v=100" name="Sakin Rahman" />);
    const img = screen.getByRole('img', { name: 'Profile picture of Sakin Rahman' }) as HTMLImageElement;
    expect(img.src).toBe(`${BACKEND_URL}/api/users/7/profile-picture?v=100`);
    expect(screen.getByTestId('profile-picture')).toHaveAttribute('data-state', 'image');
  });

  it('renders the first initial when no picture exists', () => {
    render(<ProfilePicture src={null} name="Sakin Rahman" />);
    const fallback = screen.getByRole('img', { name: 'Profile picture of Sakin Rahman' });
    expect(fallback).toHaveTextContent('S');
    expect(fallback).toHaveAttribute('data-state', 'initial');
  });

  it('falls back to a generic icon when there is no name either', () => {
    render(<ProfilePicture src={null} name="" />);
    expect(screen.getByTestId('profile-picture')).toHaveAttribute('data-state', 'icon');
    expect(screen.getByRole('img', { name: 'Profile picture' })).toBeInTheDocument();
  });

  it('falls back to the initial when the image fails to load', () => {
    render(<ProfilePicture src="/api/users/7/profile-picture?v=1" name="Sakin Rahman" />);
    fireEvent.error(screen.getByRole('img'));
    expect(screen.getByTestId('profile-picture')).toHaveAttribute('data-state', 'initial');
    expect(screen.getByText('S')).toBeInTheDocument();
  });

  it('is decorative (hidden from assistive tech) when the name is shown next to it', () => {
    render(<ProfilePicture src={null} name="Sakin Rahman" decorative />);
    expect(screen.queryByRole('img')).toBeNull();
    expect(screen.getByTestId('profile-picture')).toHaveAttribute('aria-hidden', 'true');
  });

  it('computes initials safely', () => {
    expect(initialFor('sakin')).toBe('S');
    expect(initialFor('  Dr. Grace Hopper')).toBe('D');
    expect(initialFor('')).toBe('');
    expect(initialFor(null)).toBe('');
  });
});

describe('profileService helpers', () => {
  it('validates type and size on the client', () => {
    expect(validateProfilePictureFile(png())).toBeNull();
    expect(validateProfilePictureFile(png('a.jpg', 'image/jpeg'))).toBeNull();
    expect(validateProfilePictureFile(png('a.webp', 'image/webp'))).toBeNull();
    expect(validateProfilePictureFile(png('a.gif', 'image/gif'))).toBe(PROFILE_PICTURE_TYPE_ERROR);
    expect(validateProfilePictureFile(png('a.svg', 'image/svg+xml'))).toBe(PROFILE_PICTURE_TYPE_ERROR);
    expect(validateProfilePictureFile(png('a.txt', 'text/plain'))).toBe(PROFILE_PICTURE_TYPE_ERROR);
    expect(validateProfilePictureFile(png('big.png', 'image/png', 5 * 1024 * 1024 + 1))).toBe(PROFILE_PICTURE_SIZE_ERROR);
  });

  it('resolves relative URLs and leaves absolute/null alone', () => {
    expect(resolveProfilePictureUrl(null)).toBeNull();
    expect(resolveProfilePictureUrl(undefined)).toBeNull();
    expect(resolveProfilePictureUrl('/api/users/1/profile-picture?v=2')).toBe(`${BACKEND_URL}/api/users/1/profile-picture?v=2`);
    expect(resolveProfilePictureUrl('https://cdn.example/x.webp')).toBe('https://cdn.example/x.webp');
  });
});

describe('ProfilePictureUploader', () => {
  const upload = profileService.uploadProfilePicture as unknown as ReturnType<typeof vi.fn>;
  const remove = profileService.removeProfilePicture as unknown as ReturnType<typeof vi.fn>;

  beforeEach(() => {
    upload.mockReset();
    remove.mockReset();
    (URL as unknown as { createObjectURL: unknown }).createObjectURL = vi.fn(() => 'blob:preview');
    (URL as unknown as { revokeObjectURL: unknown }).revokeObjectURL = vi.fn();
  });

  afterEach(() => {
    delete (URL as unknown as { createObjectURL?: unknown }).createObjectURL;
    delete (URL as unknown as { revokeObjectURL?: unknown }).revokeObjectURL;
  });

  const renderUploader = (user: User, onUpdated = vi.fn()) => {
    render(<MemoryRouter><ProfilePictureUploader user={user} onUpdated={onUpdated} /></MemoryRouter>);
    return { onUpdated };
  };

  it('shows the initial and an upload button when the user has no picture', () => {
    renderUploader(baseUser);
    expect(screen.getByText('S')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Change profile picture' })).toHaveTextContent('Upload photo');
    expect(screen.queryByRole('button', { name: 'Remove profile picture' })).toBeNull();
  });

  it('rejects unsupported files on the client without calling the API', () => {
    renderUploader(baseUser);
    fireEvent.change(screen.getByTestId('profile-picture-input'), { target: { files: [png('a.gif', 'image/gif')] } });
    expect(screen.getByRole('alert')).toHaveTextContent(PROFILE_PICTURE_TYPE_ERROR);
    expect(upload).not.toHaveBeenCalled();
    expect(screen.queryByTestId('profile-picture-pending')).toBeNull();
  });

  it('rejects oversized files on the client', () => {
    renderUploader(baseUser);
    fireEvent.change(screen.getByTestId('profile-picture-input'), { target: { files: [png('big.png', 'image/png', 6 * 1024 * 1024)] } });
    expect(screen.getByRole('alert')).toHaveTextContent(PROFILE_PICTURE_SIZE_ERROR);
    expect(upload).not.toHaveBeenCalled();
  });

  it('previews the selected file and only uploads after confirmation, then reports success', async () => {
    let resolveUpload: (v: unknown) => void = () => {};
    upload.mockImplementation(() => new Promise((res) => { resolveUpload = res; }));
    const { onUpdated } = renderUploader(baseUser);

    fireEvent.change(screen.getByTestId('profile-picture-input'), { target: { files: [png()] } });

    expect(upload).not.toHaveBeenCalled();
    const preview = screen.getByRole('img', { name: 'Preview of the selected profile picture' }) as HTMLImageElement;
    expect(preview.src).toContain('blob:preview');
    expect(screen.getByText('avatar.png')).toBeInTheDocument();

    const save = screen.getByRole('button', { name: 'Save new profile picture' });
    fireEvent.click(save);
    fireEvent.click(save); // duplicate click must not fire a second request
    expect(upload).toHaveBeenCalledTimes(1);
    expect(upload.mock.calls[0][0]).toBeInstanceOf(File);
    expect(screen.getByRole('button', { name: 'Save new profile picture' })).toBeDisabled();
    expect(screen.getByText('Uploading…')).toBeInTheDocument();

    await act(async () => { resolveUpload({ status: 'success', message: 'Profile picture updated successfully.', user: pictureUser }); });

    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('Profile picture updated successfully.'));
    expect(onUpdated).toHaveBeenCalledWith(pictureUser);
    expect(screen.queryByTestId('profile-picture-pending')).toBeNull();
  });

  it('shows the server validation message when the upload is rejected and keeps the current picture', async () => {
    upload.mockRejectedValue(new ApiError(422, 'Some of the submitted values are invalid.', {}, { profile_picture: ['Profile picture must be 5 MB or smaller.'] }));
    const { onUpdated } = renderUploader(pictureUser);

    fireEvent.change(screen.getByTestId('profile-picture-input'), { target: { files: [png()] } });
    fireEvent.click(screen.getByRole('button', { name: 'Save new profile picture' }));

    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Profile picture must be 5 MB or smaller.'));
    expect(onUpdated).not.toHaveBeenCalled();
    // Pending selection stays so the user can retry or cancel.
    expect(screen.getByTestId('profile-picture-pending')).toBeInTheDocument();
  });

  it('shows a friendly message for server/network failures without technical details', async () => {
    upload.mockRejectedValue(new ApiError(503, 'Unable to update your profile picture. Please try again.'));
    renderUploader(baseUser);
    fireEvent.change(screen.getByTestId('profile-picture-input'), { target: { files: [png()] } });
    fireEvent.click(screen.getByRole('button', { name: 'Save new profile picture' }));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Unable to update your profile picture. Please try again.'));

    upload.mockRejectedValue(new ApiError(0, 'Unable to reach the FacultyLens server. Check your connection and try again.'));
    fireEvent.click(screen.getByRole('button', { name: 'Save new profile picture' }));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Unable to reach the FacultyLens server'));
  });

  it('cancel discards the pending selection', () => {
    renderUploader(pictureUser);
    fireEvent.change(screen.getByTestId('profile-picture-input'), { target: { files: [png()] } });
    expect(screen.getByTestId('profile-picture-pending')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(screen.queryByTestId('profile-picture-pending')).toBeNull();
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:preview');
    expect(upload).not.toHaveBeenCalled();
  });

  it('removes the picture after confirmation and reports the fallback user', async () => {
    remove.mockResolvedValue({ status: 'success', message: 'Profile picture removed.', user: baseUser });
    const { onUpdated } = renderUploader(pictureUser);

    fireEvent.click(screen.getByRole('button', { name: 'Remove profile picture' }));
    expect(remove).not.toHaveBeenCalled();
    expect(screen.getByRole('group', { name: 'Confirm removing profile picture' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Confirm remove profile picture' }));

    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('Profile picture removed.'));
    expect(remove).toHaveBeenCalledTimes(1);
    expect(onUpdated).toHaveBeenCalledWith(baseUser);
  });

  it('"Keep" aborts the removal', () => {
    renderUploader(pictureUser);
    fireEvent.click(screen.getByRole('button', { name: 'Remove profile picture' }));
    fireEvent.click(screen.getByRole('button', { name: 'Keep' }));
    expect(screen.queryByRole('group')).toBeNull();
    expect(remove).not.toHaveBeenCalled();
  });

  it('exposes accessible controls', () => {
    renderUploader(pictureUser);
    expect(screen.getByRole('button', { name: 'Change profile picture' })).toBeVisible();
    expect(screen.getByRole('button', { name: 'Remove profile picture' })).toBeVisible();
    expect(screen.getByLabelText('Profile picture file')).toHaveAttribute('accept', expect.stringContaining('image/png'));
    expect(screen.getByRole('img', { name: 'Profile picture of Sakin Rahman' })).toBeInTheDocument();
  });
});

describe('Avatar integration in layout and settings', () => {
  const authValue = (user: User, overrides: Record<string, unknown> = {}) => ({
    user,
    isAuthenticated: true,
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
    register: vi.fn(),
    refreshUser: vi.fn(),
    updateUser: vi.fn(),
    ...overrides,
  });

  it('sidebar shows the initial without a picture and the image with one', () => {
    const { unmount } = render(
      <AuthContext.Provider value={authValue(baseUser)}>
        <MemoryRouter><Sidebar /></MemoryRouter>
      </AuthContext.Provider>,
    );
    const link = screen.getByRole('link', { name: 'Open profile settings' });
    expect(link.querySelector('[data-testid="profile-picture"]')).toHaveAttribute('data-state', 'initial');
    expect(link).toHaveTextContent('Sakin Rahman');
    unmount();

    render(
      <AuthContext.Provider value={authValue(pictureUser)}>
        <MemoryRouter><Sidebar /></MemoryRouter>
      </AuthContext.Provider>,
    );
    const img = screen.getByRole('link', { name: 'Open profile settings' }).querySelector('img') as HTMLImageElement;
    expect(img.src).toContain('/api/users/7/profile-picture?v=100');
  });

  it('settings page hosts the uploader and pushes the updated user into auth state', async () => {
    (profileService.uploadProfilePicture as unknown as ReturnType<typeof vi.fn>).mockResolvedValue({ status: 'success', message: 'Profile picture updated successfully.', user: pictureUser });
    const updateUser = vi.fn();
    render(
      <AuthContext.Provider value={authValue(baseUser, { updateUser })}>
        <MemoryRouter><Settings /></MemoryRouter>
      </AuthContext.Provider>,
    );

    expect(screen.getByTestId('profile-picture-uploader')).toBeInTheDocument();
    fireEvent.change(screen.getByTestId('profile-picture-input'), { target: { files: [png()] } });
    fireEvent.click(screen.getByRole('button', { name: 'Save new profile picture' }));

    await waitFor(() => expect(updateUser).toHaveBeenCalledWith(pictureUser));
  });
});

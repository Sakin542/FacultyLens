import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { ForgotPassword, GENERIC_RESET_MESSAGE } from '@/pages/ForgotPassword';
import { ResetPassword, INVALID_LINK_MESSAGE, validateNewPassword } from '@/pages/ResetPassword';
import { ApiError } from '@/services/api';

vi.mock('@/services/authService', () => ({
  authService: { forgotPassword: vi.fn(), resetPassword: vi.fn() },
}));
import { authService } from '@/services/authService';
const svc = authService as unknown as { forgotPassword: ReturnType<typeof vi.fn>; resetPassword: ReturnType<typeof vi.fn> };

const LoginProbe: React.FC = () => <div data-testid="login-page">Login</div>;
const ForgotProbe: React.FC = () => <div data-testid="forgot-page">Forgot</div>;

const renderReset = (path: string) => render(
  <MemoryRouter initialEntries={[path]}>
    <Routes>
      <Route path="/reset-password" element={<ResetPassword />} />
      <Route path="/login" element={<LoginProbe />} />
      <Route path="/forgot-password" element={<ForgotProbe />} />
    </Routes>
  </MemoryRouter>,
);

describe('ForgotPassword page', () => {
  beforeEach(() => { vi.clearAllMocks(); });

  it('validates the e-mail before calling the API', async () => {
    render(<MemoryRouter><ForgotPassword /></MemoryRouter>);
    const submit = screen.getByRole('button', { name: /send reset link/i });
    fireEvent.click(submit);
    expect(await screen.findByText('University email is required')).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText(/university email/i), { target: { value: 'nope' } });
    fireEvent.click(submit);
    expect(await screen.findByText('Please provide a valid email address')).toBeInTheDocument();
    expect(svc.forgotPassword).not.toHaveBeenCalled();
  });

  it('submits, shows a loading state and then the generic success message (no account disclosure)', async () => {
    let resolve!: (v: unknown) => void;
    svc.forgotPassword.mockImplementation(() => new Promise((r) => { resolve = r; }));
    render(<MemoryRouter><ForgotPassword /></MemoryRouter>);
    fireEvent.change(screen.getByLabelText(/university email/i), { target: { value: '  Faculty@University.edu ' } });
    fireEvent.click(screen.getByRole('button', { name: /send reset link/i }));

    await waitFor(() => expect(svc.forgotPassword).toHaveBeenCalledWith('Faculty@University.edu'));
    expect(screen.getByRole('button', { name: /send reset link/i })).toBeDisabled();
    resolve({ status: 'success', message: GENERIC_RESET_MESSAGE });

    await screen.findByTestId('forgot-password-success');
    expect(screen.getByRole('status')).toHaveTextContent(GENERIC_RESET_MESSAGE);
    expect(screen.queryByText(/Faculty@University.edu/)).toBeNull();
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Check your inbox.');
  });

  it('shows the same generic message for an unknown account (server decides, UI does not differ)', async () => {
    svc.forgotPassword.mockResolvedValue({ status: 'success', message: GENERIC_RESET_MESSAGE });
    render(<MemoryRouter><ForgotPassword /></MemoryRouter>);
    fireEvent.change(screen.getByLabelText(/university email/i), { target: { value: 'nonexistent@university.edu' } });
    fireEvent.click(screen.getByRole('button', { name: /send reset link/i }));
    expect(await screen.findByRole('status')).toHaveTextContent(GENERIC_RESET_MESSAGE);
  });

  it('surfaces API failures and rate limiting without leaking internals', async () => {
    svc.forgotPassword.mockRejectedValueOnce(new ApiError(500, 'SQLSTATE[HY000] boom'));
    render(<MemoryRouter><ForgotPassword /></MemoryRouter>);
    fireEvent.change(screen.getByLabelText(/university email/i), { target: { value: 'a@b.co' } });
    fireEvent.click(screen.getByRole('button', { name: /send reset link/i }));
    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('We could not process your request right now.');
    expect(alert).not.toHaveTextContent('SQLSTATE');

    svc.forgotPassword.mockRejectedValueOnce(new ApiError(429, 'Too many password reset attempts. Please wait a few minutes and try again.'));
    fireEvent.click(screen.getByRole('button', { name: /send reset link/i }));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Too many password reset attempts'));
    expect(screen.getByTestId('forgot-password-form')).toBeInTheDocument();
  });

  it('is keyboard operable: Enter submits the form', async () => {
    svc.forgotPassword.mockResolvedValue({ status: 'success' });
    render(<MemoryRouter><ForgotPassword /></MemoryRouter>);
    const input = screen.getByLabelText(/university email/i);
    fireEvent.change(input, { target: { value: 'a@b.co' } });
    fireEvent.submit(input.closest('form')!);
    await waitFor(() => expect(svc.forgotPassword).toHaveBeenCalledTimes(1));
  });
});

describe('ResetPassword page', () => {
  beforeEach(() => { vi.clearAllMocks(); });

  it('extracts token and e-mail from the URL and submits them with the new password', async () => {
    svc.resetPassword.mockResolvedValue({ status: 'success', message: 'Password reset successfully.' });
    renderReset('/reset-password?token=SECURE-TOKEN-123&email=user%40example.com');

    expect(screen.getByText(/Resetting the password for user@example.com/)).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText(/^new password/i), { target: { value: 'NewPassword#2027' } });
    fireEvent.change(screen.getByLabelText(/^confirm new password/i), { target: { value: 'NewPassword#2027' } });
    fireEvent.click(screen.getByRole('button', { name: 'Reset Password' }));

    await waitFor(() => expect(svc.resetPassword).toHaveBeenCalledWith({ token: 'SECURE-TOKEN-123', email: 'user@example.com', password: 'NewPassword#2027', password_confirmation: 'NewPassword#2027' }));
    await screen.findByTestId('reset-password-success');
    expect(screen.getByRole('status')).toHaveTextContent('Password reset successfully. You can now sign in with your new password.');

    fireEvent.click(screen.getByRole('button', { name: 'Go to Login' }));
    await screen.findByTestId('login-page');
  });

  it('validates password strength and confirmation client-side', async () => {
    renderReset('/reset-password?token=T&email=user%40example.com');
    const submit = screen.getByRole('button', { name: 'Reset Password' });

    fireEvent.click(submit);
    expect(await screen.findByText('New password is required')).toBeInTheDocument();
    expect(screen.getByText('Please confirm your new password')).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText(/^new password/i), { target: { value: 'short1' } });
    fireEvent.click(submit);
    expect(await screen.findByText('Password must be at least 8 characters')).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText(/^new password/i), { target: { value: 'onlyletters' } });
    fireEvent.click(submit);
    expect(await screen.findByText('Password must contain at least one letter and one number')).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText(/^new password/i), { target: { value: 'GoodPass123' } });
    fireEvent.change(screen.getByLabelText(/^confirm new password/i), { target: { value: 'GoodPass124' } });
    fireEvent.click(submit);
    expect(await screen.findByText('Passwords do not match')).toBeInTheDocument();
    expect(svc.resetPassword).not.toHaveBeenCalled();
    expect(validateNewPassword('Abcdefg1')).toBeNull();
  });

  it('shows the invalid-link screen when the URL lacks a token or e-mail', () => {
    renderReset('/reset-password');
    expect(screen.getByTestId('reset-password-invalid')).toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent(INVALID_LINK_MESSAGE);
  });

  it('handles an invalid or expired token from the server and offers a new link', async () => {
    svc.resetPassword.mockRejectedValue(new ApiError(422, INVALID_LINK_MESSAGE, { code: 'INVALID_RESET_TOKEN' }));
    renderReset('/reset-password?token=EXPIRED&email=user%40example.com');
    fireEvent.change(screen.getByLabelText(/^new password/i), { target: { value: 'NewPassword#2027' } });
    fireEvent.change(screen.getByLabelText(/^confirm new password/i), { target: { value: 'NewPassword#2027' } });
    fireEvent.click(screen.getByRole('button', { name: 'Reset Password' }));

    await screen.findByTestId('reset-password-invalid');
    expect(screen.getByRole('alert')).toHaveTextContent(INVALID_LINK_MESSAGE);
    fireEvent.click(screen.getByRole('button', { name: 'Request a New Link' }));
    await screen.findByTestId('forgot-page');
  });

  it('maps server-side password validation errors onto the fields', async () => {
    svc.resetPassword.mockRejectedValue(new ApiError(422, 'The given data was invalid.', {}, { password: ['The password field must be at least 8 characters.'] }));
    renderReset('/reset-password?token=T&email=user%40example.com');
    fireEvent.change(screen.getByLabelText(/^new password/i), { target: { value: 'Abcdefg1' } });
    fireEvent.change(screen.getByLabelText(/^confirm new password/i), { target: { value: 'Abcdefg1' } });
    fireEvent.click(screen.getByRole('button', { name: 'Reset Password' }));
    expect(await screen.findByText('The password field must be at least 8 characters.')).toBeInTheDocument();
    expect(screen.getByTestId('reset-password-form')).toBeInTheDocument();
  });

  it('shows a network error and keeps the form usable', async () => {
    svc.resetPassword.mockRejectedValue(new TypeError('Failed to fetch'));
    renderReset('/reset-password?token=T&email=user%40example.com');
    fireEvent.change(screen.getByLabelText(/^new password/i), { target: { value: 'Abcdefg1' } });
    fireEvent.change(screen.getByLabelText(/^confirm new password/i), { target: { value: 'Abcdefg1' } });
    fireEvent.click(screen.getByRole('button', { name: 'Reset Password' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('We could not reset your password right now.');
    expect(screen.getByRole('button', { name: 'Reset Password' })).not.toBeDisabled();
  });

  it('is keyboard accessible: labelled inputs, show/hide toggle and Enter submits', async () => {
    svc.resetPassword.mockResolvedValue({ status: 'success' });
    renderReset('/reset-password?token=T&email=user%40example.com');
    const pw = screen.getByLabelText(/^new password/i) as HTMLInputElement;
    expect(pw.type).toBe('password');
    fireEvent.click(screen.getByRole('button', { name: 'Show password' }));
    expect(pw.type).toBe('text');
    fireEvent.click(screen.getByRole('button', { name: 'Hide password' }));
    expect(pw.type).toBe('password');

    fireEvent.change(pw, { target: { value: 'Abcdefg1' } });
    fireEvent.change(screen.getByLabelText(/^confirm new password/i), { target: { value: 'Abcdefg1' } });
    fireEvent.submit(pw.closest('form')!);
    await waitFor(() => expect(svc.resetPassword).toHaveBeenCalledTimes(1));
  });
});

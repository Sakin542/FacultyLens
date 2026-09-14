import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { Footer } from '@/components/layout/Footer';
import { NewsletterLanding } from '@/pages/NewsletterLanding';
import { newsletterService } from '@/services/newsletterService';
import { ApiError } from '@/services/api';

vi.mock('@/services/newsletterService', () => ({
  newsletterService: { subscribe: vi.fn(), confirm: vi.fn(), unsubscribe: vi.fn() },
}));

const subscribe = newsletterService.subscribe as unknown as ReturnType<typeof vi.fn>;
const confirm = newsletterService.confirm as unknown as ReturnType<typeof vi.fn>;
const unsubscribe = newsletterService.unsubscribe as unknown as ReturnType<typeof vi.fn>;

const renderFooter = () => render(<MemoryRouter><Footer /></MemoryRouter>);
const emailInput = () => screen.getByLabelText('Email address');
const submit = () => fireEvent.click(screen.getByRole('button', { name: 'Subscribe' }));

describe('Faculty dispatch footer form', () => {
  beforeEach(() => { subscribe.mockReset(); confirm.mockReset(); unsubscribe.mockReset(); });

  it('validates locally before calling the API', () => {
    renderFooter();
    fireEvent.change(emailInput(), { target: { value: 'nope' } });
    submit();
    expect(screen.getByRole('alert')).toHaveTextContent('Enter a valid email address.');
    expect(subscribe).not.toHaveBeenCalled();
  });

  it('submits to the API and shows the server confirmation message', async () => {
    subscribe.mockResolvedValue({ status: 'success', message: 'Check your inbox — we sent a link to confirm your subscription.' });
    renderFooter();
    fireEvent.change(emailInput(), { target: { value: 'ada@university.edu' } });
    submit();
    submit(); // double click must not double-submit
    await waitFor(() => expect(screen.getByTestId('newsletter-success')).toHaveTextContent('Check your inbox'));
    expect(subscribe).toHaveBeenCalledTimes(1);
    expect(subscribe).toHaveBeenCalledWith('ada@university.edu');
    expect(screen.queryByTestId('newsletter-form')).toBeNull();
  });

  it('shows server-side field errors and generic failures', async () => {
    subscribe.mockRejectedValue(new ApiError(422, 'Some of the submitted values are invalid.', {}, { email: ['Enter a valid email address.'] }));
    renderFooter();
    fireEvent.change(emailInput(), { target: { value: 'a@b.co' } });
    submit();
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Enter a valid email address.'));

    subscribe.mockRejectedValue(new ApiError(429, 'Too many subscription attempts. Please try again later.'));
    submit();
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Too many subscription attempts'));
    expect(screen.getByTestId('newsletter-form')).toBeInTheDocument();
  });
});

describe('Newsletter landing pages', () => {
  const renderLanding = (mode: 'confirm' | 'unsubscribe', token = 'tok123') =>
    render(
      <MemoryRouter initialEntries={[`/newsletter/${mode}/${token}`]}>
        <Routes><Route path="/newsletter/:mode/:token" element={<NewsletterLanding mode={mode} />} /></Routes>
      </MemoryRouter>,
    );

  beforeEach(() => { confirm.mockReset(); unsubscribe.mockReset(); });

  it('confirms with the token from the URL exactly once', async () => {
    confirm.mockResolvedValue({ status: 'success', message: 'Your subscription is confirmed. Welcome to the Faculty dispatch.' });
    renderLanding('confirm', 'abc');
    await waitFor(() => expect(screen.getByTestId('newsletter-result')).toHaveTextContent('Your subscription is confirmed'));
    expect(confirm).toHaveBeenCalledTimes(1);
    expect(confirm).toHaveBeenCalledWith('abc');
    expect(unsubscribe).not.toHaveBeenCalled();
  });

  it('shows the server error for an invalid or expired link', async () => {
    confirm.mockRejectedValue(new ApiError(410, 'This confirmation link has expired. Please subscribe again to receive a new one.'));
    renderLanding('confirm');
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('has expired'));
    expect(screen.getByRole('link', { name: 'Subscribe again' })).toBeInTheDocument();
  });

  it('unsubscribes and reports success', async () => {
    unsubscribe.mockResolvedValue({ status: 'success', message: 'You have been unsubscribed. You will not receive further dispatches.' });
    renderLanding('unsubscribe', 'xyz');
    await waitFor(() => expect(screen.getByTestId('newsletter-result')).toHaveTextContent('You have been unsubscribed'));
    expect(unsubscribe).toHaveBeenCalledWith('xyz');
  });
});

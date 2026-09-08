import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { BrowserRouter } from 'react-router-dom';
import { CourseModal } from '@/components/courses/CourseModal';
import { Login } from '@/pages/Login';
import { AuthProvider } from '@/context/AuthContext';

describe('Frontend Forms and Page Validation', () => {
  describe('CourseModal Form', () => {
    it('renders input fields when open', () => {
      render(
        <CourseModal
          isOpen={true}
          onClose={() => {}}
          onSubmit={async () => {}}
        />
      );

      expect(screen.getByPlaceholderText(/e\.g\. cse 4201/i)).toBeInTheDocument();
      expect(screen.getByPlaceholderText(/e\.g\. artificial intelligence/i)).toBeInTheDocument();
    });

    it('submits valid course data to onSubmit handler', async () => {
      const handleSubmit = vi.fn().mockResolvedValue(undefined);
      render(
        <CourseModal
          isOpen={true}
          onClose={() => {}}
          onSubmit={handleSubmit}
        />
      );

      const codeInput = screen.getByPlaceholderText(/e\.g\. cse 4201/i);
      const nameInput = screen.getByPlaceholderText(/e\.g\. artificial intelligence/i);

      fireEvent.change(codeInput, { target: { value: 'CSE101' } });
      fireEvent.change(nameInput, { target: { value: 'Database Systems' } });

      const submitBtn = screen.getByRole('button', { name: /create course/i });
      fireEvent.click(submitBtn);

      await waitFor(() => {
        expect(handleSubmit).toHaveBeenCalled();
        expect(handleSubmit).toHaveBeenCalledWith(
          expect.objectContaining({
            course_code: 'CSE101',
            course_name: 'Database Systems',
          })
        );
      });
    });
  });

  describe('Login Form Validation', () => {
    it('validates empty email and password inputs', async () => {
      render(
        <BrowserRouter>
          <AuthProvider>
            <Login />
          </AuthProvider>
        </BrowserRouter>
      );

      const submitBtn = screen.getByRole('button', { name: /sign in/i });
      fireEvent.click(submitBtn);

      await waitFor(() => {
        expect(screen.getByText(/university email is required/i)).toBeInTheDocument();
        expect(screen.getByText(/password is required/i)).toBeInTheDocument();
      });
    });

    it('validates malformed email formats', async () => {
      render(
        <BrowserRouter>
          <AuthProvider>
            <Login />
          </AuthProvider>
        </BrowserRouter>
      );

      const emailInput = screen.getByPlaceholderText(/faculty@example\.com/i);
      fireEvent.change(emailInput, { target: { value: 'notanemail' } });

      const submitBtn = screen.getByRole('button', { name: /sign in/i });
      fireEvent.click(submitBtn);

      await waitFor(() => {
        expect(screen.getByText(/please provide a valid university email address/i)).toBeInTheDocument();
      });
    });
  });
});

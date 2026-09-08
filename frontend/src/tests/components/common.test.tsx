import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { Card, CardHeader, CardTitle } from '@/components/common/Card';
import { Input } from '@/components/common/Input';

describe('Common UI Components', () => {
  describe('Button Component', () => {
    it('renders with children text correctly', () => {
      render(<Button>Click Me</Button>);
      expect(screen.getByRole('button', { name: /click me/i })).toBeInTheDocument();
    });

    it('triggers onClick handler when clicked', () => {
      const handleClick = vi.fn();
      render(<Button onClick={handleClick}>Submit</Button>);
      fireEvent.click(screen.getByRole('button', { name: /submit/i }));
      expect(handleClick).toHaveBeenCalledTimes(1);
    });

    it('is disabled and prevents click when disabled prop is true', () => {
      const handleClick = vi.fn();
      render(<Button disabled onClick={handleClick}>Disabled</Button>);
      const btn = screen.getByRole('button', { name: /disabled/i });
      expect(btn).toBeDisabled();
      fireEvent.click(btn);
      expect(handleClick).not.toHaveBeenCalled();
    });

    it('shows loading state and is disabled when isLoading is true', () => {
      render(<Button isLoading>Saving...</Button>);
      const btn = screen.getByRole('button');
      expect(btn).toBeDisabled();
      expect(screen.getByText('Saving...')).toBeInTheDocument();
    });
  });

  describe('Badge Component', () => {
    it('renders label with proper text', () => {
      render(<Badge variant="Good">Completed</Badge>);
      expect(screen.getByText('Completed')).toBeInTheDocument();
    });

    it('supports different variants', () => {
      const { rerender } = render(<Badge variant="Critical">High Priority</Badge>);
      expect(screen.getByText('High Priority')).toBeInTheDocument();

      rerender(<Badge variant="Attention">Moderate</Badge>);
      expect(screen.getByText('Moderate')).toBeInTheDocument();
    });
  });

  describe('Card Component', () => {
    it('renders title and children content', () => {
      render(
        <Card>
          <CardHeader>
            <CardTitle>Assessment Card</CardTitle>
          </CardHeader>
          <p>Card Body Information</p>
        </Card>
      );
      expect(screen.getByText('Assessment Card')).toBeInTheDocument();
      expect(screen.getByText('Card Body Information')).toBeInTheDocument();
    });
  });

  describe('Input Component', () => {
    it('handles typing and value changes', () => {
      const handleChange = vi.fn();
      render(
        <Input
          placeholder="Enter Course Code"
          onChange={handleChange}
        />
      );
      const input = screen.getByPlaceholderText('Enter Course Code');
      fireEvent.change(input, { target: { value: 'CSE101' } });
      expect(handleChange).toHaveBeenCalled();
    });

    it('displays error message when provided', () => {
      render(<Input error="Course code is required" />);
      expect(screen.getByText('Course code is required')).toBeInTheDocument();
    });
  });
});

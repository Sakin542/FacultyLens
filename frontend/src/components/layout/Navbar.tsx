import React, { useState } from 'react';
import { Link, useNavigate, useLocation } from 'react-router-dom';
import { Button } from '@/components/common/Button';
import { Logo } from '@/components/common/Logo';
import { Menu, X, ArrowRight } from 'lucide-react';

export const Navbar: React.FC = () => {
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const navigate = useNavigate();
  const location = useLocation();

  const handleNavClick = (sectionId: string) => {
    setMobileMenuOpen(false);
    if (location.pathname === '/') {
      const element = document.getElementById(sectionId);
      if (element) {
        element.scrollIntoView({ behavior: 'smooth' });
      }
    } else {
      navigate('/');
      // Allow DOM to render then scroll
      setTimeout(() => {
        const element = document.getElementById(sectionId);
        if (element) {
          element.scrollIntoView({ behavior: 'smooth' });
        }
      }, 100);
    }
  };

  return (
    <header className="sticky top-0 z-50 bg-[#F7F7F5]/90 backdrop-blur-md border-b border-[#E5E5E5] transition-all">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex items-center justify-between h-16">
          {/* Animated Brand Logo */}
          <Link
            to="/"
            className="flex items-center gap-2 group"
          >
            <Logo size="md" />
          </Link>

          {/* Desktop Navigation Links */}
          <nav className="hidden md:flex items-center gap-8 text-sm font-semibold text-[#737373]">
            <button
              type="button"
              onClick={() => handleNavClick('features')}
              className="hover:text-[#111111] transition-colors duration-150 tracking-tight"
            >
              Features
            </button>
            <button
              type="button"
              onClick={() => handleNavClick('how-it-works')}
              className="hover:text-[#111111] transition-colors duration-150 tracking-tight"
            >
              How It Works
            </button>
            <button
              type="button"
              onClick={() => handleNavClick('about')}
              className="hover:text-[#111111] transition-colors duration-150 tracking-tight"
            >
              About
            </button>
          </nav>

          {/* Action CTAs */}
          <div className="hidden md:flex items-center gap-3">
            <Button
              variant="ghost"
              size="sm"
              onClick={() => navigate('/login')}
              className="text-xs font-semibold uppercase tracking-wider text-[#262626]"
            >
              Sign In
            </Button>
            <Button
              variant="primary"
              size="sm"
              rightIcon={<ArrowRight className="w-3.5 h-3.5" />}
              onClick={() => navigate('/register')}
              className="text-xs font-semibold tracking-wide"
            >
              Get Started
            </Button>
          </div>

          {/* Mobile Menu Button */}
          <div className="md:hidden flex items-center">
            <button
              type="button"
              className="p-2 rounded-lg text-[#111111] hover:bg-[#E5E5E5] focus:outline-none"
              onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
              aria-label="Toggle Navigation Menu"
            >
              {mobileMenuOpen ? <X className="w-5 h-5" /> : <Menu className="w-5 h-5" />}
            </button>
          </div>
        </div>
      </div>

      {/* Mobile Menu Dropdown */}
      {mobileMenuOpen && (
        <div className="md:hidden border-b border-[#E5E5E5] bg-[#F7F7F5] px-4 pt-2 pb-6 space-y-3">
          <button
            type="button"
            className="block w-full text-left py-2 text-sm font-semibold text-[#262626] hover:text-[#111111]"
            onClick={() => handleNavClick('features')}
          >
            Features
          </button>
          <button
            type="button"
            className="block w-full text-left py-2 text-sm font-semibold text-[#262626] hover:text-[#111111]"
            onClick={() => handleNavClick('how-it-works')}
          >
            How It Works
          </button>
          <button
            type="button"
            className="block w-full text-left py-2 text-sm font-semibold text-[#262626] hover:text-[#111111]"
            onClick={() => handleNavClick('about')}
          >
            About
          </button>
          <div className="pt-4 border-t border-[#E5E5E5] flex flex-col gap-2">
            <Button
              variant="outline"
              size="sm"
              className="w-full justify-center"
              onClick={() => {
                setMobileMenuOpen(false);
                navigate('/login');
              }}
            >
              Sign In
            </Button>
            <Button
              variant="primary"
              size="sm"
              className="w-full justify-center"
              onClick={() => {
                setMobileMenuOpen(false);
                navigate('/register');
              }}
            >
              Get Started
            </Button>
          </div>
        </div>
      )}
    </header>
  );
};

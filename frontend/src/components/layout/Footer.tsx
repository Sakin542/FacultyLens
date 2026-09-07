import React, { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Logo } from '@/components/common/Logo';
import { GraduationCap, ArrowUpRight, CheckCircle2 } from 'lucide-react';

export const Footer: React.FC = () => {
  const navigate = useNavigate();
  const [newsletterEmail, setNewsletterEmail] = useState('');
  const [newsletterSubscribed, setNewsletterSubscribed] = useState(false);

  const handleNewsletterSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!newsletterEmail || !/\S+@\S+\.\S+/.test(newsletterEmail)) return;
    setNewsletterSubscribed(true);
    setNewsletterEmail('');
    setTimeout(() => setNewsletterSubscribed(false), 4000);
  };

  return (
    <footer className="bg-white border-t border-[#E5E5E5] py-12 md:py-16 text-[#111111] transition-all">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="grid grid-cols-1 md:grid-cols-12 gap-10 md:gap-8 mb-12">
          {/* Brand & Mission Column */}
          <div className="md:col-span-4 space-y-4 text-left">
            <Link to="/" className="inline-block">
              <Logo size="md" />
            </Link>
            <p className="text-xs text-[#737373] leading-relaxed max-w-sm">
              AI-Powered Academic Decision Support System for University Faculty to analyze syllabi, map learning outcomes, detect question similarities, and evaluate assessment quality.
            </p>
            <div className="flex items-center gap-2 pt-2">
              <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-[#F7F7F5] border border-[#E5E5E5] text-[11px] font-medium text-[#262626]">
                <GraduationCap className="w-3.5 h-3.5 text-[#111111]" />
                Higher Ed Decision Suite
              </span>
              <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-[#F0FDF4] border border-[#BBF7D0] text-[11px] font-semibold text-[#166534]">
                <span className="w-1.5 h-1.5 rounded-full bg-[#16A34A] animate-pulse" />
                AI Operational
              </span>
            </div>
          </div>

          {/* Quick Navigation */}
          <div className="md:col-span-2 space-y-3 text-left">
            <h4 className="text-xs font-bold uppercase tracking-wider text-[#111111]">Platform</h4>
            <ul className="space-y-2.5 text-xs text-[#737373] font-medium">
              <li>
                <Link to="/#features" className="hover:text-[#111111] transition-colors">
                  Features
                </Link>
              </li>
              <li>
                <Link to="/#how-it-works" className="hover:text-[#111111] transition-colors">
                  How It Works
                </Link>
              </li>
              <li>
                <Link to="/#about" className="hover:text-[#111111] transition-colors">
                  Academic Philosophy
                </Link>
              </li>
              <li>
                <button
                  type="button"
                  onClick={() => navigate('/dashboard')}
                  className="hover:text-[#111111] transition-colors flex items-center gap-1"
                >
                  Live Demo Portal <ArrowUpRight className="w-3 h-3" />
                </button>
              </li>
            </ul>
          </div>

          {/* Academic Modules */}
          <div className="md:col-span-3 space-y-3 text-left">
            <h4 className="text-xs font-bold uppercase tracking-wider text-[#111111]">Capabilities</h4>
            <ul className="space-y-2.5 text-xs text-[#737373] font-medium">
              <li>
                <Link to="/courses" className="hover:text-[#111111] transition-colors">
                  Curriculum & CLO Mapping
                </Link>
              </li>
              <li>
                <Link to="/assessments" className="hover:text-[#111111] transition-colors">
                  Assessment Upload & Parsing
                </Link>
              </li>
              <li>
                <Link to="/analysis" className="hover:text-[#111111] transition-colors">
                  Bloom Taxonomy Audit
                </Link>
              </li>
              <li>
                <Link to="/history" className="hover:text-[#111111] transition-colors">
                  Accreditation History Logs
                </Link>
              </li>
            </ul>
          </div>

          {/* Academic Newsletter / Updates */}
          <div className="md:col-span-3 space-y-3 text-left">
            <h4 className="text-xs font-bold uppercase tracking-wider text-[#111111]">Faculty Dispatch</h4>
            <p className="text-xs text-[#737373] leading-relaxed">
              Subscribe to periodic updates on academic AI governance and assessment design methodologies.
            </p>

            {newsletterSubscribed ? (
              <div className="flex items-center gap-2 p-2.5 bg-[#F0FDF4] border border-[#BBF7D0] rounded-lg text-xs text-[#166534] font-medium">
                <CheckCircle2 className="w-4 h-4 shrink-0" />
                <span>Thank you for subscribing!</span>
              </div>
            ) : (
              <form onSubmit={handleNewsletterSubmit} className="space-y-2">
                <div className="flex items-center gap-2">
                  <input
                    type="email"
                    placeholder="faculty@university.edu"
                    value={newsletterEmail}
                    onChange={(e) => setNewsletterEmail(e.target.value)}
                    required
                    className="w-full text-xs px-3 py-2 rounded-lg border border-[#E5E5E5] bg-[#F7F7F5] text-[#111111] placeholder:text-[#A3A3A3] focus:outline-none focus:ring-2 focus:ring-[#111111] focus:bg-white transition-all"
                  />
                  <button
                    type="submit"
                    className="px-3 py-2 text-xs font-semibold bg-[#111111] text-white rounded-lg hover:bg-[#262626] transition-colors shrink-0"
                  >
                    Join
                  </button>
                </div>
              </form>
            )}
          </div>
        </div>

        {/* Bottom Copyright & Legal Links */}
        <div className="pt-8 border-t border-[#E5E5E5] flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-[#737373]">
          <div className="flex items-center gap-2 text-center sm:text-left">
            <span>© {new Date().getFullYear()} FacultyLens Academic Systems.</span>
            <span className="hidden sm:inline">•</span>
            <span className="hidden sm:inline">Built for Higher Education Excellence.</span>
          </div>

          <div className="flex items-center gap-6 font-medium">
            <Link to="/login" className="hover:text-[#111111] transition-colors">
              Faculty Sign In
            </Link>
            <Link to="/register" className="hover:text-[#111111] transition-colors">
              Institutional Registration
            </Link>
            <Link to="/forgot-password" className="hover:text-[#111111] transition-colors">
              Password Recovery
            </Link>
          </div>
        </div>
      </div>
    </footer>
  );
};


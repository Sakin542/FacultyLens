import React from 'react';
import { useNavigate } from 'react-router-dom';
import { Button } from '@/components/common/Button';
import { Logo } from '@/components/common/Logo';
import { ArrowLeft, Home as HomeIcon } from 'lucide-react';

export const NotFound: React.FC = () => {
  const navigate = useNavigate();

  return (
    <div className="min-h-screen flex items-center justify-center p-4 bg-[#F7F7F5] text-center">
      <div className="max-w-md w-full bg-white p-8 sm:p-12 rounded-2xl border border-[#E5E5E5] shadow-card space-y-6">
        <div className="flex justify-center">
          <Logo size="xl" showSubtitle />
        </div>

        <div className="space-y-2 pt-2">
          <span className="font-mono text-xs uppercase font-bold tracking-widest text-[#737373]">
            404 — Page Not Found
          </span>
          <h1 className="text-2xl sm:text-3xl font-extrabold text-[#111111] tracking-tight">
            Academic Lens Misaligned
          </h1>
          <p className="text-xs text-[#737373] leading-relaxed">
            The page or assessment route you requested does not exist or has been relocated within the university portal.
          </p>
        </div>

        <div className="flex flex-col sm:flex-row items-center justify-center gap-3 pt-2">
          <Button
            variant="outline"
            size="sm"
            className="w-full sm:w-auto font-medium"
            leftIcon={<ArrowLeft className="w-4 h-4" />}
            onClick={() => navigate(-1)}
          >
            Go Back
          </Button>
          <Button
            variant="primary"
            size="sm"
            className="w-full sm:w-auto font-medium"
            leftIcon={<HomeIcon className="w-4 h-4" />}
            onClick={() => navigate('/dashboard')}
          >
            Go to Dashboard
          </Button>
        </div>
      </div>
    </div>
  );
};

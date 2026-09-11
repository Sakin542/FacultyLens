import React from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, FileBarChart2, ShieldCheck } from 'lucide-react';

interface ReportHeaderProps {
  title: string;
  subtitle?: string;
  backTo?: { to: string; label: string };
  actions?: React.ReactNode;
  badge?: React.ReactNode;
}

/** STEP 39 page header: title, evidence note and page-level actions. */
export const ReportHeader: React.FC<ReportHeaderProps> = ({ title, subtitle, backTo, actions, badge }) => (
  <div className="bg-white border border-sage-200 rounded-xl px-5 py-4 mb-6 shadow-subtle">
    {backTo && (
      <Link to={backTo.to} className="inline-flex items-center gap-1.5 text-xs font-medium text-sage-500 hover:text-sage-800 transition-colors mb-3">
        <ArrowLeft className="w-3.5 h-3.5" />
        {backTo.label}
      </Link>
    )}
    <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
      <div className="flex items-start gap-3 min-w-0">
        <span className="hidden sm:inline-flex w-10 h-10 shrink-0 items-center justify-center rounded-xl bg-sage-100 text-sage-700 border border-sage-200">
          <FileBarChart2 className="w-5 h-5" />
        </span>
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="font-serif text-2xl text-sage-800 leading-tight">{title}</h2>
            {badge}
          </div>
          {subtitle && <p className="text-xs text-sage-500 mt-1">{subtitle}</p>}
          <p className="text-[11px] text-sage-400 mt-1 inline-flex items-center gap-1">
            <ShieldCheck className="w-3.5 h-3.5 text-sage-600" />
            Reports are evidence artifacts for authorized review — FacultyLens never changes grades, assessments or mappings automatically.
          </p>
        </div>
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2 shrink-0">{actions}</div>}
    </div>
  </div>
);

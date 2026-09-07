import React from 'react';
import { Card } from '@/components/common/Card';
import { FeedbackSummaryData } from '@/types/feedback';
import {
  CheckCircle2,
  XCircle,
  Eye,
  Star,
  Layers,
} from 'lucide-react';

interface FeedbackSummaryCardsProps {
  summary: FeedbackSummaryData | null;
  isLoading?: boolean;
}

export const FeedbackSummaryCards: React.FC<FeedbackSummaryCardsProps> = ({
  summary,
  isLoading = false,
}) => {
  if (isLoading || !summary) {
    return (
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3.5">
        {[1, 2, 3, 4, 5].map((idx) => (
          <div
            key={idx}
            className="h-24 rounded-xl bg-neutral-100 dark:bg-neutral-800 animate-pulse"
          />
        ))}
      </div>
    );
  }

  const acceptanceRate = summary.analytics?.acceptance_rate ?? (
    summary.total > 0 ? Math.round((summary.accepted / summary.total) * 100) : 0
  );

  const stats = [
    {
      title: 'Total Decisions',
      value: summary.total,
      subtitle: 'Recorded faculty decisions',
      icon: Layers,
      color: 'text-neutral-700 dark:text-neutral-300',
      bg: 'bg-neutral-100 dark:bg-neutral-800',
    },
    {
      title: 'Accepted',
      value: summary.accepted,
      subtitle: `${acceptanceRate}% acceptance rate`,
      icon: CheckCircle2,
      color: 'text-emerald-600 dark:text-emerald-400',
      bg: 'bg-emerald-50 dark:bg-emerald-950/30',
    },
    {
      title: 'Dismissed',
      value: summary.dismissed,
      subtitle: summary.total > 0 ? `${Math.round((summary.dismissed / summary.total) * 100)}% dismissed` : '0% dismissed',
      icon: XCircle,
      color: 'text-neutral-500 dark:text-neutral-400',
      bg: 'bg-neutral-100 dark:bg-neutral-800/60',
    },
    {
      title: 'Under Review',
      value: summary.reviewed,
      subtitle: 'Marked for consideration',
      icon: Eye,
      color: 'text-blue-600 dark:text-blue-400',
      bg: 'bg-blue-50 dark:bg-blue-950/30',
    },
    {
      title: 'Avg Usefulness',
      value: summary.average_usefulness !== null ? `${summary.average_usefulness} / 5` : 'N/A',
      subtitle: 'Faculty star rating',
      icon: Star,
      color: 'text-amber-600 dark:text-amber-400',
      bg: 'bg-amber-50 dark:bg-amber-950/30',
    },
  ];

  return (
    <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3.5">
      {stats.map((stat, i) => {
        const Icon = stat.icon;
        return (
          <Card
            key={i}
            className="p-4 bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-xs flex flex-col justify-between"
          >
            <div className="flex items-center justify-between gap-2">
              <span className="text-xs font-medium text-[#737373] tracking-tight">
                {stat.title}
              </span>
              <div
                className={`w-7 h-7 rounded-lg flex items-center justify-center shrink-0 ${stat.bg} ${stat.color}`}
              >
                <Icon className="w-4 h-4" />
              </div>
            </div>

            <div className="mt-2">
              <span className="text-xl font-bold font-mono text-[#111111] dark:text-white block">
                {stat.value}
              </span>
              <span className="text-[11px] text-[#737373] mt-0.5 block truncate">
                {stat.subtitle}
              </span>
            </div>
          </Card>
        );
      })}
    </div>
  );
};


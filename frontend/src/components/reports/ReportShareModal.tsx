import React, { useState } from 'react';
import { X, Copy, Check, Link, Globe, ShieldCheck } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { GeneratedPdfReportInfo } from '@/types/report';

interface ReportShareModalProps {
  isOpen: boolean;
  onClose: () => void;
  reportInfo: GeneratedPdfReportInfo | null;
  onShare: () => Promise<void>;
  onRevoke: () => Promise<void>;
  isSharing: boolean;
  isRevoking: boolean;
}

export const ReportShareModal: React.FC<ReportShareModalProps> = ({
  isOpen,
  onClose,
  reportInfo,
  onShare,
  onRevoke,
  isSharing,
  isRevoking,
}) => {
  const [copied, setCopied] = useState(false);

  if (!isOpen) return null;

  const isShareActive = Boolean(reportInfo?.is_shareable && reportInfo?.share_token);
  const shareUrl = isShareActive
    ? `${window.location.origin}/shared/reports/${reportInfo?.share_token}`
    : '';

  const handleCopy = async () => {
    if (!shareUrl) return;
    try {
      await navigator.clipboard.writeText(shareUrl);
      setCopied(true);
      setTimeout(() => setCopied(false), 2500);
    } catch {
      // fallback
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 animate-in fade-in duration-150">
      <div className="bg-white rounded-xl max-w-lg w-full p-6 shadow-xl border border-sage-200">
        <div className="flex items-center justify-between pb-4 border-b border-sage-100">
          <div className="flex items-center gap-2">
            <Globe className="w-5 h-5 text-sage-800" />
            <h3 className="text-base font-bold text-sage-800">
              Share Academic Assessment Report
            </h3>
          </div>
          <button
            onClick={onClose}
            className="text-sage-500 hover:text-sage-800 p-1 rounded-md transition-colors"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        <div className="py-5 space-y-4">
          <p className="text-xs text-sage-600 leading-relaxed">
            Generate a secure, view-only public link to share this assessment quality report with academic committee members, department chairs, or external accreditation evaluators.
          </p>

          {isShareActive ? (
            <div className="space-y-4">
              <div className="p-3 bg-emerald-50 border border-emerald-200 rounded-lg flex items-center gap-2.5 text-xs text-emerald-800">
                <ShieldCheck className="w-4 h-4 text-emerald-600 shrink-0" />
                <span>
                  This report is actively shared. Anyone with the link can view the read-only report.
                </span>
              </div>

              <div>
                <label className="block text-xs font-semibold text-sage-800 mb-1.5">
                  Public Share Link:
                </label>
                <div className="flex items-center gap-2">
                  <input
                    type="text"
                    readOnly
                    value={shareUrl}
                    className="flex-1 text-xs font-mono bg-sage-100 border border-sage-200 rounded-lg px-3 py-2 text-sage-800 select-all focus:outline-none focus:ring-1 focus:ring-sage-600"
                  />
                  <Button
                    variant="primary"
                    size="sm"
                    onClick={handleCopy}
                    leftIcon={copied ? <Check className="w-3.5 h-3.5" /> : <Copy className="w-3.5 h-3.5" />}
                  >
                    {copied ? 'Copied!' : 'Copy'}
                  </Button>
                </div>
              </div>

              <div className="flex items-center justify-between pt-3 border-t border-sage-100">
                <a
                  href={shareUrl}
                  target="_blank"
                  rel="noreferrer"
                  className="text-xs text-blue-600 hover:text-blue-800 font-medium hover:underline inline-flex items-center gap-1"
                >
                  <Link className="w-3.5 h-3.5" />
                  Open shared report page &rarr;
                </a>

                <Button
                  variant="danger"
                  size="sm"
                  onClick={onRevoke}
                  disabled={isRevoking}
                  isLoading={isRevoking}
                >
                  Revoke Share Access
                </Button>
              </div>
            </div>
          ) : (
            <div className="space-y-4">
              <div className="p-3.5 bg-[#F8FAFC] border border-[#E2E8F0] rounded-lg text-xs text-[#475569] space-y-1.5">
                <div className="font-semibold text-[#1E293B]">Controlled Sharing Policy:</div>
                <ul className="list-disc list-inside space-y-1 text-[#64748B] text-[11px]">
                  <li>Generates an unguessable 40-character secure token.</li>
                  <li>Recipients receive view-only access without edit capabilities.</li>
                  <li>You can revoke access immediately at any time.</li>
                </ul>
              </div>

              <Button
                variant="primary"
                className="w-full"
                onClick={onShare}
                disabled={isSharing}
                isLoading={isSharing}
                leftIcon={<Globe className="w-4 h-4" />}
              >
                Enable Shareable Link
              </Button>
            </div>
          )}
        </div>

        <div className="pt-3 border-t border-sage-100 flex justify-end">
          <Button variant="outline" size="sm" onClick={onClose}>
            Close
          </Button>
        </div>
      </div>
    </div>
  );
};

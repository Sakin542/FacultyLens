import React from 'react';
import { DocumentProcessing } from '@/types';
import { Button } from '@/components/common/Button';
import {
  FileText,
  Download,
  Trash2,
  RefreshCw,
  Eye,
  AlertCircle,
  CheckCircle,
  Clock,
  FileCode,
} from 'lucide-react';
import { Link } from 'react-router-dom';

interface DocumentListProps {
  documents: DocumentProcessing[];
  isLoading: boolean;
  onOpenUpload: () => void;
  onDownload: (doc: DocumentProcessing) => void;
  onDelete: (id: number | string) => void;
  onReprocess?: (id: number | string) => void;
}

export const DocumentList: React.FC<DocumentListProps> = ({
  documents,
  isLoading,
  onOpenUpload,
  onDownload,
  onDelete,
  onReprocess,
}) => {
  const formatFileSize = (bytes: number): string => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'completed':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800/40">
            <CheckCircle className="h-3 w-3" />
            Extracted
          </span>
        );
      case 'processing':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-400 border border-amber-200 dark:border-amber-800/40">
            <Clock className="h-3 w-3 animate-spin" />
            Processing
          </span>
        );
      case 'failed':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950/40 dark:text-red-400 border border-red-200 dark:border-red-800/40">
            <AlertCircle className="h-3 w-3" />
            Failed
          </span>
        );
      default:
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300">
            <Clock className="h-3 w-3" />
            Uploaded
          </span>
        );
    }
  };

  const getDocTypeBadge = (type: string) => {
    const labels: Record<string, { label: string; color: string }> = {
      syllabus: { label: 'Syllabus', color: 'bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-950/40 dark:text-purple-300 dark:border-purple-800/40' },
      question_paper: { label: 'Question Paper', color: 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:border-blue-800/40' },
      assignment: { label: 'Assignment', color: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800/40' },
      previous_exam: { label: 'Past Exam', color: 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800/40' },
      other: { label: 'Document', color: 'bg-slate-50 text-slate-700 border-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700' },
    };

    const config = labels[type] || labels.other;
    return (
      <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold border ${config.color}`}>
        {config.label}
      </span>
    );
  };

  if (isLoading) {
    return (
      <div className="flex h-40 items-center justify-center">
        <div className="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
          <Clock className="h-4 w-4 animate-spin text-indigo-600" />
          Loading documents...
        </div>
      </div>
    );
  }

  if (documents.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-200 dark:border-slate-700/80 p-8 text-center bg-slate-50/50 dark:bg-slate-900/20">
        <div className="flex h-12 w-12 items-center justify-center rounded-full bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 mb-3">
          <FileText className="h-6 w-6" />
        </div>
        <h4 className="text-base font-semibold text-slate-900 dark:text-white">No documents uploaded yet</h4>
        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400 max-w-sm">
          Upload course syllabus, past exams, or question paper drafts (PDF, DOCX, TXT) to automatically extract and analyze text.
        </p>
        <Button
          onClick={onOpenUpload}
          variant="primary"
          size="sm"
          className="mt-4 flex items-center gap-2"
        >
          <FileText className="h-4 w-4" />
          Upload First Document
        </Button>
      </div>
    );
  }

  return (
    <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-700/80 dark:bg-slate-800/80">
      <div className="overflow-x-auto">
        <table className="w-full text-left text-sm text-slate-600 dark:text-slate-300">
          <thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:bg-slate-800 dark:text-slate-400 border-b border-slate-200 dark:border-slate-700">
            <tr>
              <th className="px-6 py-3.5 font-semibold">Document Name</th>
              <th className="px-4 py-3.5 font-semibold">Category</th>
              <th className="px-4 py-3.5 font-semibold">Size</th>
              <th className="px-4 py-3.5 font-semibold">Status</th>
              <th className="px-4 py-3.5 font-semibold">Extracted Preview</th>
              <th className="px-6 py-3.5 text-right font-semibold">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 dark:divide-slate-700/60">
            {documents.map((doc) => (
              <tr
                key={doc.id}
                className="hover:bg-slate-50/75 dark:hover:bg-slate-750 transition-colors"
              >
                <td className="px-6 py-4">
                  <div className="flex items-center gap-3">
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600 dark:bg-indigo-950/60 dark:text-indigo-400">
                      <FileCode className="h-5 w-5" />
                    </div>
                    <div>
                      <Link
                        to={`/documents/${doc.id}`}
                        className="font-medium text-slate-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400 transition-colors"
                      >
                        {doc.original_file_name}
                      </Link>
                      {doc.assessment && (
                        <div className="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
                          Linked to: {doc.assessment.title}
                        </div>
                      )}
                    </div>
                  </div>
                </td>
                <td className="px-4 py-4 whitespace-nowrap">
                  {getDocTypeBadge(doc.document_type)}
                </td>
                <td className="px-4 py-4 whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">
                  {formatFileSize(doc.file_size)}
                </td>
                <td className="px-4 py-4 whitespace-nowrap">
                  {getStatusBadge(doc.processing_status)}
                </td>
                <td className="px-4 py-4 max-w-xs">
                  {doc.cleaned_text ? (
                    <p className="truncate text-xs text-slate-500 dark:text-slate-400 font-mono">
                      {doc.cleaned_text.slice(0, 70)}...
                    </p>
                  ) : doc.processing_error ? (
                    <span className="text-xs text-red-500 truncate block">
                      {doc.processing_error}
                    </span>
                  ) : (
                    <span className="text-xs text-slate-400 italic">No text extracted</span>
                  )}
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-right">
                  <div className="flex items-center justify-end gap-1">
                    <Link
                      to={`/documents/${doc.id}`}
                      title="View Details & Extracted Content"
                      className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-indigo-600 dark:text-slate-400 dark:hover:bg-slate-700 dark:hover:text-indigo-400 transition-colors"
                    >
                      <Eye className="h-4 w-4" />
                    </Link>

                    {onReprocess && doc.processing_status === 'failed' && (
                      <button
                        onClick={() => onReprocess(doc.id)}
                        title="Reprocess Extraction"
                        className="rounded-lg p-1.5 text-amber-600 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-950/40 transition-colors"
                      >
                        <RefreshCw className="h-4 w-4" />
                      </button>
                    )}

                    <button
                      onClick={() => onDownload(doc)}
                      title="Download Original File"
                      className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-slate-700 dark:hover:text-slate-200 transition-colors"
                    >
                      <Download className="h-4 w-4" />
                    </button>

                    <button
                      onClick={() => onDelete(doc.id)}
                      title="Delete Document"
                      className="rounded-lg p-1.5 text-slate-500 hover:bg-red-50 hover:text-red-600 dark:text-slate-400 dark:hover:bg-red-950/40 dark:hover:text-red-400 transition-colors"
                    >
                      <Trash2 className="h-4 w-4" />
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
};


import React, { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { documentService } from '@/services/documentService';
import { DocumentProcessing } from '@/types';
import { Button } from '@/components/common/Button';
import {
  ArrowLeft,
  FileText,
  Download,
  Trash2,
  RefreshCw,
  Copy,
  Check,
  AlertCircle,
  Clock,
  Calendar,
  Layers,
  Database,
} from 'lucide-react';

export const DocumentDetails: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();

  const [document, setDocument] = useState<DocumentProcessing | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isReprocessing, setIsReprocessing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'cleaned' | 'raw' | 'metadata'>('cleaned');
  const [copied, setCopied] = useState(false);

  const fetchDocument = async () => {
    if (!id) return;
    try {
      setIsLoading(true);
      setError(null);
      const res = await documentService.getById(id);
      setDocument(res.data);
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError('Failed to load document details.');
      }
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchDocument();
  }, [id]);

  const handleCopyText = (text?: string | null) => {
    if (!text) return;
    navigator.clipboard.writeText(text);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  const handleDownload = async () => {
    if (!document) return;
    try {
      await documentService.download(document.id, document.original_file_name);
    } catch (err) {
      console.error(err);
      alert('Failed to download document.');
    }
  };

  const handleReprocess = async () => {
    if (!document) return;
    try {
      setIsReprocessing(true);
      const res = await documentService.reprocess(document.id);
      setDocument(res.data);
    } catch (err: unknown) {
      if (err instanceof Error) {
        alert(err.message);
      } else {
        alert('Failed to reprocess document.');
      }
    } finally {
      setIsReprocessing(false);
    }
  };

  const handleDelete = async () => {
    if (!document) return;
    if (!window.confirm(`Are you sure you want to delete "${document.original_file_name}"?`)) {
      return;
    }

    try {
      await documentService.delete(document.id);
      if (document.course_id) {
        navigate(`/courses/${document.course_id}`);
      } else {
        navigate('/courses');
      }
    } catch (err) {
      console.error(err);
      alert('Failed to delete document.');
    }
  };

  const formatFileSize = (bytes: number): string => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
  };

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <div className="flex items-center gap-3 text-slate-500 dark:text-slate-400">
          <Clock className="h-5 w-5 animate-spin text-indigo-600" />
          <span>Loading document content...</span>
        </div>
      </div>
    );
  }

  if (error || !document) {
    return (
      <div className="p-6">
        <div className="rounded-xl border border-red-200 bg-red-50 p-6 dark:border-red-900/50 dark:bg-red-950/30">
          <div className="flex items-center gap-3 text-red-700 dark:text-red-400">
            <AlertCircle className="h-6 w-6" />
            <h3 className="text-lg font-semibold">Document Not Found</h3>
          </div>
          <p className="mt-2 text-sm text-red-600 dark:text-red-300">
            {error || 'The requested document could not be found or you do not have permission to access it.'}
          </p>
          <div className="mt-4">
            <Button variant="outline" size="sm" onClick={() => navigate(-1)}>
              Go Back
            </Button>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Top Breadcrumb / Navigation */}
      <div className="flex items-center justify-between">
        <button
          onClick={() => navigate(-1)}
          className="inline-flex items-center gap-2 text-sm font-medium text-slate-600 hover:text-indigo-600 dark:text-slate-400 dark:hover:text-indigo-400 transition-colors"
        >
          <ArrowLeft className="h-4 w-4" />
          Back to Course
        </button>

        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={handleReprocess}
            disabled={isReprocessing}
            className="flex items-center gap-1.5"
          >
            <RefreshCw className={`h-4 w-4 ${isReprocessing ? 'animate-spin' : ''}`} />
            Reprocess
          </Button>

          <Button
            variant="outline"
            size="sm"
            onClick={handleDownload}
            className="flex items-center gap-1.5"
          >
            <Download className="h-4 w-4" />
            Download Original
          </Button>

          <Button
            variant="outline"
            size="sm"
            onClick={handleDelete}
            className="flex items-center gap-1.5 text-red-600 hover:bg-red-50 hover:text-red-700 dark:text-red-400 dark:hover:bg-red-950/40"
          >
            <Trash2 className="h-4 w-4" />
            Delete
          </Button>
        </div>
      </div>

      {/* Main Info Card */}
      <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700/80 dark:bg-slate-800/80">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div className="flex items-start gap-4">
            <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 dark:bg-indigo-950/60 dark:text-indigo-400">
              <FileText className="h-6 w-6" />
            </div>
            <div>
              <div className="flex items-center gap-2 flex-wrap">
                <h1 className="text-xl font-bold text-slate-900 dark:text-white">
                  {document.original_file_name}
                </h1>
                <span className="inline-flex items-center rounded-md bg-indigo-50 px-2.5 py-0.5 text-xs font-semibold uppercase text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800/40">
                  {document.document_type.replace('_', ' ')}
                </span>
                <span
                  className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium border ${
                    document.processing_status === 'completed'
                      ? 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-400'
                      : document.processing_status === 'failed'
                      ? 'bg-red-50 text-red-700 border-red-200 dark:bg-red-950/40 dark:text-red-400'
                      : 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-400'
                  }`}
                >
                  {document.processing_status.toUpperCase()}
                </span>
              </div>

              <div className="mt-2 flex items-center gap-4 text-xs text-slate-500 dark:text-slate-400 flex-wrap">
                {document.course && (
                  <span className="flex items-center gap-1">
                    <Layers className="h-3.5 w-3.5" />
                    Course: <strong className="text-slate-700 dark:text-slate-200">{document.course.code || document.course.course_code || document.course.title}</strong>
                  </span>
                )}
                <span className="flex items-center gap-1">
                  <Database className="h-3.5 w-3.5" />
                  Size: {formatFileSize(document.file_size)}
                </span>
                <span className="flex items-center gap-1">
                  <Calendar className="h-3.5 w-3.5" />
                  Uploaded: {document.created_at ? new Date(document.created_at).toLocaleDateString() : 'N/A'}
                </span>
              </div>
            </div>
          </div>
        </div>

        {/* Failure Warning */}
        {document.processing_status === 'failed' && (
          <div className="mt-4 flex items-start gap-2.5 rounded-lg bg-red-50 p-3.5 text-xs text-red-700 dark:bg-red-950/40 dark:text-red-400 border border-red-200 dark:border-red-900/50">
            <AlertCircle className="h-4 w-4 shrink-0 text-red-500 mt-0.5" />
            <div>
              <strong>Extraction Error:</strong> {document.processing_error || 'Unknown error during text extraction.'}
            </div>
          </div>
        )}
      </div>

      {/* Content Tabs */}
      <div className="space-y-4">
        <div className="flex items-center justify-between border-b border-slate-200 dark:border-slate-700">
          <div className="flex items-center gap-4">
            <button
              onClick={() => setActiveTab('cleaned')}
              className={`pb-3 text-sm font-medium transition-colors border-b-2 ${
                activeTab === 'cleaned'
                  ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                  : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-300'
              }`}
            >
              Cleaned & Normalized Text
            </button>

            <button
              onClick={() => setActiveTab('raw')}
              className={`pb-3 text-sm font-medium transition-colors border-b-2 ${
                activeTab === 'raw'
                  ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                  : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-300'
              }`}
            >
              Raw Extracted Text
            </button>

            <button
              onClick={() => setActiveTab('metadata')}
              className={`pb-3 text-sm font-medium transition-colors border-b-2 ${
                activeTab === 'metadata'
                  ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                  : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-300'
              }`}
            >
              Technical Metadata
            </button>
          </div>

          {(activeTab === 'cleaned' || activeTab === 'raw') && (
            <button
              onClick={() => handleCopyText(activeTab === 'cleaned' ? document.cleaned_text : document.extracted_text)}
              className="mb-2 inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800 transition-colors"
            >
              {copied ? <Check className="h-3.5 w-3.5 text-emerald-600" /> : <Copy className="h-3.5 w-3.5" />}
              {copied ? 'Copied!' : 'Copy Text'}
            </button>
          )}
        </div>

        {/* Cleaned Text Tab */}
        {activeTab === 'cleaned' && (
          <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700/80 dark:bg-slate-800/80">
            {document.cleaned_text ? (
              <pre className="whitespace-pre-wrap font-sans text-sm leading-relaxed text-slate-800 dark:text-slate-200">
                {document.cleaned_text}
              </pre>
            ) : (
              <div className="py-12 text-center text-sm text-slate-400 dark:text-slate-500">
                No cleaned text available for this document.
              </div>
            )}
          </div>
        )}

        {/* Raw Text Tab */}
        {activeTab === 'raw' && (
          <div className="rounded-xl border border-slate-200 bg-slate-900 p-6 shadow-sm dark:border-slate-700/80">
            {document.extracted_text ? (
              <pre className="whitespace-pre-wrap font-mono text-xs leading-relaxed text-slate-100 overflow-x-auto">
                {document.extracted_text}
              </pre>
            ) : (
              <div className="py-12 text-center text-sm text-slate-500">
                No raw extracted text available.
              </div>
            )}
          </div>
        )}

        {/* Metadata Tab */}
        {activeTab === 'metadata' && (
          <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700/80 dark:bg-slate-800/80">
            <dl className="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-2 text-sm">
              <div className="border-b border-slate-100 dark:border-slate-700/60 pb-3">
                <dt className="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Original Filename</dt>
                <dd className="mt-1 font-semibold text-slate-900 dark:text-white">{document.original_file_name}</dd>
              </div>

              <div className="border-b border-slate-100 dark:border-slate-700/60 pb-3">
                <dt className="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">MIME Content-Type</dt>
                <dd className="mt-1 font-mono text-xs text-slate-700 dark:text-slate-300">{document.mime_type || 'N/A'}</dd>
              </div>

              <div className="border-b border-slate-100 dark:border-slate-700/60 pb-3">
                <dt className="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">File Storage Path</dt>
                <dd className="mt-1 font-mono text-xs text-slate-700 dark:text-slate-300">{document.file_path || 'N/A'}</dd>
              </div>

              <div className="border-b border-slate-100 dark:border-slate-700/60 pb-3">
                <dt className="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Processed Timestamp</dt>
                <dd className="mt-1 text-slate-700 dark:text-slate-300">{document.processed_at ? new Date(document.processed_at).toLocaleString() : 'Pending'}</dd>
              </div>

              <div className="sm:col-span-2">
                <dt className="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Cleaned Character Length</dt>
                <dd className="mt-1 font-semibold text-indigo-600 dark:text-indigo-400">
                  {document.cleaned_text ? `${document.cleaned_text.length} characters` : '0 characters'}
                </dd>
              </div>
            </dl>
          </div>
        )}
      </div>
    </div>
  );
};

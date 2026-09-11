import React from 'react';
import { AlertTriangle, Clock, Database, Lock } from 'lucide-react';
import { ReportPreview } from '@/types/report';
import { ReportSummary } from './ReportSummary';
import { ReportDataTable } from './ReportDataTable';
import { ReportMetadataPanel } from './ReportMetadata';

interface ReportPreviewProps {
  preview: ReportPreview;
}

/** Data preview before generation: configuration, record count, warnings, summary, sections and truncated tables. */
export const ReportPreviewPanel: React.FC<ReportPreviewProps> = ({ preview }) => (
  <div className="space-y-5" data-testid="report-preview">
    <div className="flex flex-wrap items-center gap-2 text-xs">
      <span className="inline-flex items-center gap-1.5 rounded-full border border-sage-200 bg-white px-2.5 py-1 text-sage-700"><Database className="w-3.5 h-3.5" />{preview.record_count} record{preview.record_count === 1 ? '' : 's'} · ~{preview.estimated_size}</span>
      {preview.will_queue && <span className="inline-flex items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-amber-900"><Clock className="w-3.5 h-3.5" />Will be generated in the background</span>}
      {preview.contains_student_data && <span className="inline-flex items-center gap-1.5 rounded-full border border-sage-300 bg-sage-50 px-2.5 py-1 text-sage-700"><Lock className="w-3.5 h-3.5" />Aggregated student data — no identities</span>}
    </div>

    {!preview.has_data && (
      <div role="alert" className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <p className="font-semibold">No data available for the selected filters.</p>
        <p className="text-xs mt-0.5">Adjust the scope or filters. FacultyLens does not generate empty reports.</p>
      </div>
    )}

    {preview.warnings.length > 0 && (
      <div className="rounded-lg border border-sage-200 bg-sage-50 px-4 py-3">
        <p className="text-xs font-semibold text-sage-700 inline-flex items-center gap-1.5 mb-1"><AlertTriangle className="w-3.5 h-3.5" />Notes</p>
        <ul className="list-disc pl-5 space-y-0.5">
          {preview.warnings.map((w, i) => <li key={i} className="text-xs text-sage-600">{w}</li>)}
        </ul>
      </div>
    )}

    <ReportMetadataPanel metadata={preview.metadata} recordCount={preview.record_count} />
    <ReportSummary items={preview.summary} />

    {preview.sections.map((s) => (
      <section key={s.key} className="rounded-xl border border-sage-200 bg-white p-4">
        <h4 className="text-sm font-semibold text-sage-800">{s.title}</h4>
        {s.description && <p className="text-xs text-sage-500 mt-0.5">{s.description}</p>}
        {s.text && <p className="text-xs text-sage-700 mt-2 leading-relaxed">{s.text}</p>}
        {s.items && s.items.length > 0 && (
          <dl className="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1">
            {s.items.map((it, i) => (
              <div key={i} className="flex gap-2 text-xs"><dt className="w-40 shrink-0 text-sage-500">{it.label}</dt><dd className="text-sage-800">{it.value === null || it.value === '' ? 'N/A' : String(it.value)}</dd></div>
            ))}
          </dl>
        )}
      </section>
    ))}

    <div className="space-y-4">
      {preview.tables.map((t) => <ReportDataTable key={t.key} table={t} />)}
    </div>
  </div>
);

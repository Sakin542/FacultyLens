import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/common/Card';
import { ApiError } from '@/services/api';
import { institutionalReportService } from '@/services/institutionalReportService';
import { ExportFormat, ReportFilter, ReportFilterKey, ReportFilterOptions, ReportPreview, ReportScope, ReportTypeKey, ReportTypesResponse } from '@/types/report';
import { ReportHeader } from '@/components/reports/institutional/ReportHeader';
import { ReportTypeSelector } from '@/components/reports/institutional/ReportTypeSelector';
import { ReportScopeSelector } from '@/components/reports/institutional/ReportScopeSelector';
import { ReportFilters } from '@/components/reports/institutional/ReportFilters';
import { ExportFormatSelector } from '@/components/reports/institutional/ExportFormatSelector';
import { ReportGenerationButton } from '@/components/reports/institutional/ReportGenerationButton';
import { ReportPreviewPanel } from '@/components/reports/institutional/ReportPreview';
import { ReportAccessNotice } from '@/components/reports/institutional/ReportAccessNotice';
import { ReportLoading } from '@/components/reports/institutional/ReportLoading';
import { ReportError } from '@/components/reports/institutional/ReportError';

type FieldErrors = Partial<Record<ReportFilterKey, string>>;

const REQUIRED: Record<ReportScope, ReportFilterKey[]> = {
  FACULTY: [],
  COURSE: ['course_id'],
  ASSESSMENT: ['course_id', 'assessment_id'],
  ASSESSMENT_VERSION: ['course_id', 'assessment_id', 'assessment_version_id'],
  DEPARTMENT: ['department'],
  INSTITUTION: [],
};

/** /reports/create — Report Type → Scope → Filters → Format → Preview → Generate. */
export const ReportBuilder: React.FC = () => {
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const [registry, setRegistry] = useState<ReportTypesResponse | null>(null);
  const [options, setOptions] = useState<ReportFilterOptions | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [type, setType] = useState<ReportTypeKey | ''>((params.get('type') as ReportTypeKey) || '');
  const [scope, setScope] = useState<ReportScope | ''>((params.get('scope') as ReportScope) || '');
  const [filters, setFilters] = useState<ReportFilter>(() => {
    const f: ReportFilter = {};
    (['course_id', 'assessment_id', 'assessment_version_id'] as ReportFilterKey[]).forEach((k) => { const v = params.get(k); if (v) f[k] = v; });
    return f;
  });
  const [format, setFormat] = useState<ExportFormat>('PDF');
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});

  const [preview, setPreview] = useState<ReportPreview | null>(null);
  const [previewing, setPreviewing] = useState(false);
  const [generating, setGenerating] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setLoadError(null);
      try {
        const [t, f] = await Promise.all([institutionalReportService.getReportTypes(), institutionalReportService.getReportFilters()]);
        if (cancelled) return;
        setRegistry(t.data);
        setOptions(f.data);
      } catch (err) {
        if (!cancelled) setLoadError(err instanceof Error ? err.message : 'The report builder could not be loaded.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, []);

  const selectedType = useMemo(() => registry?.types.find((t) => t.key === type) ?? null, [registry, type]);
  const scopes = selectedType?.scopes ?? [];
  const applicable: ReportFilterKey[] = scope && registry ? registry.scope_filters[scope] ?? [] : [];

  // Reset dependent state when the configuration changes
  const resetOutput = useCallback(() => { setPreview(null); setError(null); setForbidden(null); setFieldErrors({}); }, []);
  const changeType = (t: ReportTypeKey) => {
    setType(t);
    const next = registry?.types.find((x) => x.key === t);
    if (!next || (scope && !next.scopes.includes(scope))) setScope(next && next.scopes.length === 1 ? next.scopes[0] : '');
    resetOutput();
  };
  const changeScope = (s: ReportScope) => {
    setScope(s);
    const keep = registry?.scope_filters[s] ?? [];
    setFilters((f) => Object.fromEntries(Object.entries(f).filter(([k]) => keep.includes(k as ReportFilterKey))) as ReportFilter);
    resetOutput();
  };
  const changeFilters = (f: ReportFilter) => { setFilters(f); resetOutput(); };

  const validate = (): boolean => {
    if (!scope) return false;
    const errs: FieldErrors = {};
    REQUIRED[scope].forEach((k) => { if (!filters[k]) errs[k] = 'Required for this scope.'; });
    if (filters.start_date && filters.end_date && String(filters.start_date) > String(filters.end_date)) errs.start_date = 'Start date must be before the end date.';
    setFieldErrors(errs);
    return Object.keys(errs).length === 0;
  };

  const handleApiError = (err: unknown, fallback: string) => {
    if (err instanceof ApiError) {
      if (err.status === 403) { setForbidden(err.message); return; }
      const fe: FieldErrors = {};
      Object.entries(err.errors ?? {}).forEach(([k, msgs]) => { const key = k.replace(/^filters\./, '') as ReportFilterKey; if (msgs?.[0]) fe[key] = msgs[0]; });
      setFieldErrors(fe);
      setError(err.message || fallback);
      return;
    }
    setError(err instanceof Error && err.message ? err.message : fallback);
  };

  const canPreview = Boolean(type && scope) && !previewing;
  const canGenerate = Boolean(preview && preview.has_data) && !generating;

  const runPreview = async () => {
    if (!type || !scope || !validate()) return;
    setPreviewing(true);
    setError(null);
    setForbidden(null);
    try {
      const res = await institutionalReportService.previewReport({ report_type: type, scope_type: scope, filters });
      setPreview(res.data);
    } catch (err) {
      setPreview(null);
      handleApiError(err, 'The preview could not be generated.');
    } finally {
      setPreviewing(false);
    }
  };

  const runGenerate = async () => {
    if (!type || !scope || !preview || !validate()) return;
    setGenerating(true);
    setError(null);
    try {
      const res = await institutionalReportService.createReport({ report_type: type, scope_type: scope, filters, format });
      navigate(`/reports/${res.data.id}`, { state: { justCreated: true } });
    } catch (err) {
      handleApiError(err, 'Report generation failed. Please try again.');
    } finally {
      setGenerating(false);
    }
  };

  if (loading) return <ReportLoading message="Loading report builder…" />;
  if (loadError || !registry) return <ReportError message={loadError ?? 'The report builder could not be loaded.'} onRetry={() => window.location.reload()} />;

  return (
    <div className="space-y-5">
      <ReportHeader title="Create Institutional Report" subtitle="Configure → preview the data → generate → download. Every generated report snapshots its filters and assessment version." backTo={{ to: '/reports', label: 'Back to My Reports' }} />

      <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,380px)_1fr] gap-5 items-start">
        <Card className="xl:sticky xl:top-24">
          <CardHeader>
            <CardTitle>Report Configuration</CardTitle>
            <CardDescription>Only report types and scopes you are authorized for are listed.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <ReportTypeSelector types={registry.types} value={type} onChange={changeType} disabled={generating} />
            <ReportScopeSelector scopes={scopes} value={scope} onChange={changeScope} disabled={generating || !type} />
            <ReportFilters scope={scope} applicable={applicable} options={options?.options ?? null} value={filters} onChange={changeFilters} disabled={generating} errors={fieldErrors} />
            <ExportFormatSelector value={format} onChange={setFormat} allowed={selectedType?.formats} disabled={generating} largeDataset={Boolean(preview && preview.record_count > 300)} />
            <div className="pt-1 border-t border-sage-100">
              <ReportGenerationButton onPreview={runPreview} onGenerate={runGenerate} canPreview={canPreview} canGenerate={canGenerate} previewing={previewing} generating={generating} willQueue={preview?.will_queue} />
              {!preview && type && scope && <p className="mt-2 text-[11px] text-sage-500">Preview validates access and shows the data before anything is generated.</p>}
            </div>
          </CardContent>
        </Card>

        <div className="space-y-4 min-w-0">
          {forbidden && <ReportAccessNotice message={forbidden} />}
          {error && !forbidden && <ReportError message={error} compact onRetry={preview ? undefined : runPreview} />}
          {previewing && <ReportLoading message="Building preview…" compact />}
          {preview && !previewing && <ReportPreviewPanel preview={preview} />}
          {!preview && !previewing && !error && !forbidden && (
            <Card variant="muted">
              <CardContent className="py-10 text-center">
                <p className="text-sm font-medium text-sage-800">Data preview appears here</p>
                <p className="text-xs text-sage-500 mt-1 max-w-md mx-auto">Select a report type, scope and filters, then click <strong>Preview Report</strong>. You will see record counts, metadata, summary figures and the first rows of each table before generating a file.</p>
              </CardContent>
            </Card>
          )}
        </div>
      </div>
    </div>
  );
};

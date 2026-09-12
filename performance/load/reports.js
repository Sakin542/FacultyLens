// §24 Report generation: preview → create (PDF/CSV/XLSX) → poll if queued → download, for small (10q) / medium (100q)
// / large (200q, 200 submissions) assessments and course scope. Records generation time, queue time and file size.
import { sleep } from 'k6';
import { api, ensureSession, vuEmail, trend, timed, summarize, json } from './lib/session.js';
import { profileOptions, resultPath } from './lib/profiles.js';

export const options = profileOptions({ iterations: parseInt(__ENV.ITERATIONS || '12', 10) });
const tPreview = trend('report_preview');
const tCreate = { small: trend('report_create_small'), medium: trend('report_create_medium'), large: trend('report_create_large'), course: trend('report_create_course') };
const tDownload = { PDF: trend('report_download_pdf'), CSV: trend('report_download_csv'), XLSX: trend('report_download_xlsx') };
const tList = trend('reports_list');
const tQueued = trend('report_queue_to_completed');
const sizes = { small: 10, medium: 100, large: 200 };
const MATRIX = [
  ['ASSESSMENT_QUALITY', 'PDF'], ['QUESTION_ANALYSIS', 'CSV'], ['STUDENT_PERFORMANCE', 'XLSX'], ['GRADING', 'CSV'], ['ASSESSMENT', 'PDF'], ['CO_COVERAGE', 'CSV'],
];

export default function () {
  if (!ensureSession()) { sleep(1); return; }
  const body = json(api('GET', '/assessments?per_page=100', undefined, { name: 'assessments_list' }));
  const all = body && body.data ? (Array.isArray(body.data) ? body.data : body.data.data || []) : [];
  const bucket = ['small', 'medium', 'large', 'course'][__ITER % 4];
  const [type, format] = MATRIX[Math.floor(__ITER / 4) % MATRIX.length];
  let payload;
  if (bucket === 'course') {
    const a = all[0];
    payload = { report_type: 'PO_COVERAGE', scope_type: 'COURSE', filters: { course_id: a.course_id }, format: 'CSV' };
  } else {
    const a = all.find((x) => x.title && x.title.includes(`(${sizes[bucket]} questions)`));
    if (!a) return;
    payload = { report_type: type, scope_type: 'ASSESSMENT', filters: { course_id: a.course_id, assessment_id: a.id }, format };
  }

  timed(tList, () => api('GET', '/reports?per_page=20', undefined, { name: 'reports_list' }));
  const pv = json(timed(tPreview, () => api('POST', '/reports/preview', { report_type: payload.report_type, scope_type: payload.scope_type, filters: payload.filters }, { name: 'report_preview' })));
  const created = timed(tCreate[bucket], () => api('POST', '/reports', payload, { name: `report_create_${bucket}` }));
  const c = json(created);
  const id = c && c.data ? c.data.id : null;
  if (!id) { console.log(`[reports] create ${bucket} ${payload.report_type}/${payload.format} → ${created.status} ${created.body.slice(0, 160)}`); return; }

  let status = c.data.status; const t0 = Date.now();
  if (created.status === 202 || status !== 'COMPLETED') {
    for (let i = 0; i < 90 && status !== 'COMPLETED' && status !== 'FAILED'; i++) {
      sleep(2);
      const s = json(api('GET', `/reports/${id}`, undefined, { name: 'report_show' }));
      status = s && s.data ? (s.data.report ? s.data.report.status : s.data.status) : status;
    }
    tQueued.add(Date.now() - t0);
  }
  if (status === 'COMPLETED') {
    const dl = timed(tDownload[payload.format], () => api('GET', `/reports/${id}/download`, undefined, { name: `report_download_${payload.format.toLowerCase()}` }));
    console.log(`[reports] ${bucket} ${payload.report_type}/${payload.format}: create=${created.status} records=${pv && pv.data ? pv.data.record_count : '?'} bytes=${dl.body ? dl.body.length : 0} dl=${dl.status}`);
  } else {
    console.log(`[reports] ${bucket} ${payload.report_type}/${payload.format}: status=${status}`);
  }
  sleep(Number(__ENV.THINK_TIME || 4));
}

export function handleSummary(data) {
  const out = summarize(data, 'reports');
  return { [resultPath('reports')]: JSON.stringify(out, null, 2), stdout: `\n${JSON.stringify(out.endpoints, null, 2)}\n` };
}

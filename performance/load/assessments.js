// §9/§10 Assessments by size (10/50/100/200 questions), analysis read, history, question bank (5000 previous questions).
import { sleep } from 'k6';
import { api, ensureSession, vuEmail, trend, timed, summarize, json } from './lib/session.js';
import { profileOptions, resultPath } from './lib/profiles.js';

export const options = profileOptions();
const tList = trend('assessments_list');
const tHistory = trend('assessments_history');
const tShow = { 10: trend('assessment_show_10q'), 50: trend('assessment_show_50q'), 100: trend('assessment_show_100q'), 200: trend('assessment_show_200q') };
const tAnalysis = { 10: trend('analysis_read_10q'), 50: trend('analysis_read_50q'), 100: trend('analysis_read_100q'), 200: trend('analysis_read_200q') };
const tStatus = trend('analysis_status');
const tRecs = trend('recommendations_list');
const tAnalysisHistory = trend('analysis_history');
const tSubmissions = trend('submissions_list');
const tSubmissionSummary = trend('submissions_summary');
const tPerformance = trend('performance_read');
const tBank = trend('question_bank_list');
const tBankSearch = trend('question_bank_search');
const tVersions = trend('versions_list');
const tBlueprint = trend('blueprint_read');

const SIZES = [10, 50, 100, 200];

export function setup() {
  return {};
}

function pickBySize(list, n) {
  return list.find((a) => a.title && a.title.includes(`(${n} questions)`)) || null;
}

export default function () {
  if (!ensureSession()) { sleep(1); return; }

  const body = json(timed(tList, () => api('GET', '/assessments?per_page=100', undefined, { name: 'assessments_list' })));
  const all = body && body.data ? (Array.isArray(body.data) ? body.data : body.data.data || []) : [];
  if (__ITER === 0) console.log(`[assessments] list items=${all.length} paginated=${body && body.data && !Array.isArray(body.data)}`);
  timed(tHistory, () => api('GET', '/assessments/history?per_page=20', undefined, { name: 'assessments_history' }));

  const size = SIZES[__ITER % SIZES.length];
  const a = pickBySize(all, size);
  if (a) {
    const show = timed(tShow[size], () => api('GET', `/assessments/${a.id}`, undefined, { name: `assessment_show_${size}q` }));
    if (__ITER < SIZES.length) console.log(`[assessments] show ${size}q bytes=${show.body.length}`);
    timed(tAnalysis[size], () => api('GET', `/ai/assessments/${a.id}/analysis`, undefined, { name: `analysis_read_${size}q` }));
    timed(tStatus, () => api('GET', `/ai/assessments/${a.id}/analysis-status`, undefined, { name: 'analysis_status' }));
    timed(tRecs, () => api('GET', `/ai/assessments/${a.id}/recommendations`, undefined, { name: 'recommendations_list' }));
    timed(tAnalysisHistory, () => api('GET', `/assessments/${a.id}/analysis-history`, undefined, { name: 'analysis_history' }));
    timed(tSubmissions, () => api('GET', `/assessments/${a.id}/submissions?per_page=20`, undefined, { name: 'submissions_list' }));
    timed(tSubmissionSummary, () => api('GET', `/assessments/${a.id}/submissions/summary`, undefined, { name: 'submissions_summary' }));
    timed(tPerformance, () => api('GET', `/assessments/${a.id}/performance`, undefined, { name: 'performance_read' }));
    timed(tVersions, () => api('GET', `/assessments/${a.id}/versions`, undefined, { name: 'versions_list' }));
    timed(tBlueprint, () => api('GET', `/assessments/${a.id}/blueprint`, undefined, { name: 'blueprint_read' }));

    const courseId = a.course_id;
    const bank = timed(tBank, () => api('GET', `/courses/${courseId}/previous-questions?per_page=25&page=${1 + (__ITER % 20)}`, undefined, { name: 'question_bank_list' }));
    if (__ITER === 0) console.log(`[question-bank] page bytes=${bank.body.length} status=${bank.status}`);
    timed(tBankSearch, () => api('GET', `/courses/${courseId}/previous-questions?per_page=25&search=normalization&difficulty=hard`, undefined, { name: 'question_bank_search' }));
  }
  sleep(Number(__ENV.THINK_TIME || 10));
}

export function handleSummary(data) {
  const out = summarize(data, 'assessments');
  return { [resultPath('assessments')]: JSON.stringify(out, null, 2), stdout: `\n${JSON.stringify(out.endpoints, null, 2)}\n` };
}

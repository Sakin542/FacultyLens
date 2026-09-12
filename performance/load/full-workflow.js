// §31–§33 Representative faculty workflow used for concurrency (ramp), spike and soak profiles:
//   login → dashboard (analytics overview + notifications) → courses → open course → assessments → open assessment
//   → view analysis → analytics course page → (every 5th iteration) create a small CSV report → download
// One account per VU; each VU keeps its session for the whole run (a real faculty member does not log in per click).
import { sleep } from 'k6';
import { api, ensureSession, vuEmail, trend, timed, summarize, json } from './lib/session.js';
import { profileOptions, resultPath } from './lib/profiles.js';

export const options = profileOptions();
const t = {
  login: trend('login'), overview: trend('analytics_overview'), notifications: trend('notifications'), courses: trend('courses_list'), course: trend('course_show'),
  assessments: trend('assessments_list'), assessment: trend('assessment_show'), analysis: trend('analysis_read'), analyticsCourse: trend('analytics_course'),
  report: trend('report_create_small'), download: trend('report_download_csv'), journey: trend('journey_total'),
};

export default function () {
  const t0 = Date.now();
  if (!ensureSession(vuEmail(), t.login)) { sleep(2); return; }

  timed(t.overview, () => api('GET', '/analytics/overview', undefined, { name: 'analytics_overview' }));
  timed(t.notifications, () => api('GET', '/notifications?unread=1&per_page=10', undefined, { name: 'notifications' }));
  const courses = json(timed(t.courses, () => api('GET', '/courses', undefined, { name: 'courses_list' })));
  const clist = courses && courses.data ? (Array.isArray(courses.data) ? courses.data : courses.data.data || []) : [];
  if (!clist.length) { sleep(1); return; }
  const c = clist[(__VU + __ITER) % clist.length];
  timed(t.course, () => api('GET', `/courses/${c.id}`, undefined, { name: 'course_show' }));
  const as = json(timed(t.assessments, () => api('GET', `/courses/${c.id}/assessments`, undefined, { name: 'assessments_list' })));
  const alist = as && as.data ? (Array.isArray(as.data) ? as.data : as.data.data || []) : [];
  if (alist.length) {
    const a = alist[(__VU + __ITER) % alist.length];
    timed(t.assessment, () => api('GET', `/assessments/${a.id}`, undefined, { name: 'assessment_show' }));
    timed(t.analysis, () => api('GET', `/ai/assessments/${a.id}/analysis`, undefined, { name: 'analysis_read' }));
    timed(t.analyticsCourse, () => api('GET', `/analytics/courses/${c.id}`, undefined, { name: 'analytics_course' }));
    if (__ITER % 5 === 4) {
      const small = alist.find((x) => x.title && x.title.includes('(10 questions)')) || a;
      const r = json(timed(t.report, () => api('POST', '/reports', { report_type: 'QUESTION_ANALYSIS', scope_type: 'ASSESSMENT', filters: { course_id: c.id, assessment_id: small.id }, format: 'CSV' }, { name: 'report_create_small' })));
      if (r && r.data && r.data.status === 'COMPLETED') timed(t.download, () => api('GET', `/reports/${r.data.id}/download`, undefined, { name: 'report_download_csv' }));
    }
  }
  t.journey.add(Date.now() - t0);
  sleep(Number(__ENV.THINK_TIME || 5));
}

export function handleSummary(data) {
  const out = summarize(data, 'full-workflow');
  return { [resultPath('full-workflow')]: JSON.stringify(out, null, 2), stdout: `\n${JSON.stringify(out.endpoints, null, 2)}\n` };
}

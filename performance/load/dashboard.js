// §5/§14/§15 Dashboard + analytics overview (the single heaviest read page). Logs in once per VU, then
// alternates cached reads with an occasional ?fresh=1 to measure cache MISS vs HIT explicitly.
import { sleep } from 'k6';
import { api, ensureSession, vuEmail, trend, timed, summarize, json } from './lib/session.js';
import { profileOptions, resultPath } from './lib/profiles.js';

export const options = profileOptions();
const tOverview = trend('analytics_overview');
const tOverviewFresh = trend('analytics_overview_fresh');
const tNotifications = trend('notifications');
const tCourses = trend('courses_list');
const tCourseAnalytics = trend('analytics_course');
const tCourseSections = trend('analytics_course_sections');

export function setup() {
  return {};
}

export default function () {
  if (!ensureSession()) { sleep(1); return; }

  const fresh = __ITER % 10 === 0;
  const res = timed(fresh ? tOverviewFresh : tOverview, () => api('GET', `/analytics/overview${fresh ? '?fresh=1' : ''}`, undefined, { name: fresh ? 'analytics_overview_fresh' : 'analytics_overview' }));
  timed(tNotifications, () => api('GET', '/notifications?unread=1&per_page=10', undefined, { name: 'notifications' }));
  const courses = json(timed(tCourses, () => api('GET', '/courses', undefined, { name: 'courses_list' })));
  const list = courses && courses.data ? (Array.isArray(courses.data) ? courses.data : courses.data.data || []) : [];
  if (list.length) {
    const c = list[__ITER % list.length];
    timed(tCourseAnalytics, () => api('GET', `/analytics/courses/${c.id}`, undefined, { name: 'analytics_course' }));
    for (const s of ['assessments', 'performance', 'outcomes']) {
      timed(tCourseSections, () => api('GET', `/analytics/courses/${c.id}/${s}`, undefined, { name: `analytics_course_${s}` }));
    }
  }
  if (res.status !== 200) sleep(1);
  sleep(Number(__ENV.THINK_TIME || 4));
}

export function handleSummary(data) {
  const out = summarize(data, 'dashboard');
  return { [resultPath('dashboard')]: JSON.stringify(out, null, 2), stdout: `\n${JSON.stringify(out.endpoints, null, 2)}\n` };
}

// §8 Course API: list (10 courses as collaborator; 1000 courses as owner), show, create, update, delete.
import { sleep } from 'k6';
import { api, ensureSession, vuEmail, LIST_EMAIL, trend, timed, summarize, json } from './lib/session.js';
import { profileOptions, resultPath } from './lib/profiles.js';

export const options = profileOptions();
const tList = trend('courses_list');
const tList1000 = trend('courses_list_1000');
const tShow = trend('course_show');
const tCreate = trend('course_create');
const tUpdate = trend('course_update');
const tDelete = trend('course_delete');
const tAssessments = trend('course_assessments');
const tLos = trend('course_learning_outcomes');

// VU 1 in baseline is the 1000-course owner so pagination behaviour of a very large list is measured too.
function identity() {
  return (__VU === 1 && (__ENV.PROFILE || 'baseline') === 'baseline') ? LIST_EMAIL : vuEmail();
}

export default function () {
  const me = identity();
  if (!ensureSession(me)) { sleep(1); return; }

  const big = me === LIST_EMAIL;
  const listRes = timed(big ? tList1000 : tList, () => api('GET', '/courses', undefined, { name: big ? 'courses_list_1000' : 'courses_list' }));
  const body = json(listRes);
  const list = body && body.data ? (Array.isArray(body.data) ? body.data : body.data.data || []) : [];
  if (__ITER === 0) {
    console.log(`[courses] ${me} list status=${listRes.status} bytes=${listRes.body.length} items=${list.length} paginated=${body && body.data && !Array.isArray(body.data)}`);
  }
  if (list.length) {
    const c = list[__ITER % list.length];
    timed(tShow, () => api('GET', `/courses/${c.id}`, undefined, { name: 'course_show' }));
    timed(tAssessments, () => api('GET', `/courses/${c.id}/assessments`, undefined, { name: 'course_assessments' }));
    timed(tLos, () => api('GET', `/courses/${c.id}/learning-outcomes`, undefined, { name: 'course_learning_outcomes' }));
  }

  // write path: create → update → delete (own course, always cleaned up)
  const code = `K6-${__VU}-${__ITER}-${Date.now() % 100000}`;
  const created = json(timed(tCreate, () => api('POST', '/courses', { course_code: code, course_name: 'k6 course', semester: 'Fall', academic_year: '2026', credits: 3, status: 'active' }, { name: 'course_create' })));
  const id = created && created.data ? created.data.id : null;
  if (id) {
    timed(tUpdate, () => api('PUT', `/courses/${id}`, { course_name: 'k6 course (updated)' }, { name: 'course_update' }));
    timed(tDelete, () => api('DELETE', `/courses/${id}`, undefined, { name: 'course_delete' }));
  }
  sleep(Number(__ENV.THINK_TIME || 4));
}

export function handleSummary(data) {
  const out = summarize(data, 'courses');
  return { [resultPath('courses')]: JSON.stringify(out, null, 2), stdout: `\n${JSON.stringify(out.endpoints, null, 2)}\n` };
}

// §7 Authentication: csrf → login → /auth/user → logout for each iteration, one account per VU.
// Each iteration presents a new client IP (IP_PER_ITER) so the 20 logins/min/IP throttle — verified separately in
// rate-limits.js — does not mask login capacity.
import { sleep } from 'k6';
import { api, login, logout, vuEmail, rotateIp, trend, timed, summarize } from './lib/session.js';
import { profileOptions, resultPath } from './lib/profiles.js';

export const options = profileOptions();
const tLogin = trend('login');
const tUser = trend('auth_user');
const tLogout = trend('logout');

export default function () {
  rotateIp();
  if (!login(vuEmail(), tLogin)) { sleep(1); return; }
  timed(tUser, () => api('GET', '/auth/user', undefined, { name: 'auth_user' }));
  timed(tLogout, () => logout());
  sleep(0.5);
}

export function handleSummary(data) {
  const out = summarize(data, 'auth');
  return { [resultPath('auth')]: JSON.stringify(out, null, 2), stdout: `\n${JSON.stringify(out.endpoints, null, 2)}\n` };
}

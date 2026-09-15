# FacultyLens Real-Time Notification Troubleshooting Guide

This document lists common operational issues, diagnostics, and recovery procedures for the real-time notification subsystem.

---

## 1. Quick Diagnostics Checklist

| Check | Diagnostic Command | Expected Output |
|---|---|---|
| Reverb container running | `docker compose ps reverb` | Status: `Up` |
| Reverb logs | `docker compose logs --tail=50 reverb` | `INFO Starting server on 0.0.0.0:8080` |
| Redis connectivity | `docker compose exec app redis-cli -h redis ping` | `PONG` |
| Channel auth endpoint | `curl -i http://localhost:8080/api/broadcasting/auth` | `401 Unauthorized` (without token) |
| Backend tests | `docker compose exec app php artisan test --filter=RealtimeBroadcastingTest` | 5 passed |
| Frontend tests | `npm run test src/tests/components/notificationsRealtime.test.tsx` | 8 passed |

---

## 2. Common Issues & Solutions

### Issue A: Notification Bell does not increment when an event occurs

**Symptoms:** An action is taken (e.g. AI grading completes or rubric is approved), but the bell count stays static until manual browser refresh.

**Probable Causes & Fixes:**
1. **Reverb container is not started:**
   - Run `docker compose up -d reverb`.
2. **Broadcasting connection is set to `null` or `log` in backend `.env`:**
   - Verify `BROADCAST_CONNECTION=reverb` in `backend/.env`.
   - Run `docker compose exec app php artisan config:clear`.
3. **Frontend VITE variables mismatch:**
   - Verify `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT` match `backend/.env`.
   - Re-bundle or restart Vite dev server (`npm run dev`) to reload `.env`.
4. **Broadcast authorization failure:**
   - Check browser console for `403 Forbidden` on `/api/broadcasting/auth`.
   - Ensure the user has an active Sanctum session token in localStorage (`faculty_token`).

---

### Issue B: Reverb connection repeatedly drops or shows "unavailable"

**Symptoms:** Browser console reports `WebSocket connection to 'ws://...' failed: Error in connection establishment`.

**Probable Causes & Fixes:**
1. **Firewall or corporate proxy blocking WebSockets:**
   - FacultyLens automatically falls back to polling mode (`45s` interval).
   - In production, ensure WSS over port 443 is used to pass through corporate firewalls.
2. **Container port collision:**
   - Reverb port `8085` on host may be occupied by another service.
   - Run `netstat -ano | findstr 8085` on Windows to check for conflicts.
3. **Redis memory exhausted or service unavailable:**
   - Reverb relies on Redis for multi-worker scaling.
   - Run `docker compose logs redis` to check memory usage and health.

---

### Issue C: Replay of same notification causes duplicate increments

**Symptoms:** Badge displays 5 instead of 4 after network reconnection.

**Fix implemented in system:**
- `NotificationContext` implements strict ID deduplication:
  ```ts
  if (prev.some((item) => item.id === n.id)) {
      return prev; // Deduplicate replay
  }
  ```
- Backend database enforces `unique(user_id, dedupe_key)` constraint.

---

### Issue D: Notification persistence works, but Reverb server is offline

**Expected System Behavior:**
- FacultyLens is built with strict resilience guards:
  ```php
  try {
      broadcast(new NotificationCreated($notification));
  } catch (\Throwable $e) {
      Log::warning('Real-time notification broadcast failed', [...]);
  }
  ```
- Academic operations (grading, reports, rubric approval) and MySQL notification storage will **NEVER** fail even if Reverb or Redis is stopped.
- As soon as Reverb resumes, new notifications broadcast seamlessly, and existing clients resync their unread count on reconnect.


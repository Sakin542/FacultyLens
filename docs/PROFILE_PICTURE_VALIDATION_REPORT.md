# FacultyLens — Profile Picture Validation Report

**Feature:** Faculty profile picture (avatar) system · **Branch:** `feature/profile-picture` · **Date:** 2026-09-14
**Verdict:** PASS — the upload → validation → processing → private storage → database → auth state → display →
replace → remove flow was executed end to end against the live Docker stack, not only in unit tests.

## 1. Scope validated

| Area | How | Result |
|------|-----|--------|
| Backend feature tests | `php artisan test tests/Feature/Profile` on host PHP (no GD → passthrough path) | 63 passed, 1 skipped (GD-only) |
| Backend feature tests with GD | `docker compose exec -T app php artisan test tests/Feature/Profile` (GD + WEBP + exif) | 63 passed, 1 skipped (no-GD-only) — includes `image is normalised to a square webp` |
| Full backend regression | `php artisan test` (host) | 691 passed, 2 skipped (pre-existing live-AI skips) |
| Frontend unit tests | `npm run test` (Vitest + RTL) | 25 files / 329 tests passed (20 new in `profile.test.tsx`) |
| Type check / build | `tsc --noEmit`, `npm run build` | clean |
| Playwright E2E | `npx playwright test tests/e2e/12-profile-picture.spec.ts` against Docker :8080 | 3/3 passed (2.7 min) |
| Docker validation | rebuilt `facultylens-app` with GD/WEBP/exif; `migrate --force`; inspected `storage/app/private/profile-pictures/{id}/*.webp` → `image/webp 512x512`; audit rows present | PASS |

## 2. Acceptance criteria

| Criterion | Evidence |
|-----------|----------|
| Upload / preview / confirm | E2E 1; `ProfilePictureUploader` tests (preview blob, no upload before "Save photo", duplicate click blocked) |
| JPEG / PNG / WEBP accepted | `ProfilePictureUploadTest::test_authenticated_user_can_upload_each_supported_format` (3 data sets) |
| Size limit (5 MB) with friendly message | `test_file_larger_than_limit_is_rejected_with_friendly_message`; client test `rejects oversized files` |
| MIME/content validation (fake `.jpg`, SVG, text, wrong extension) | `ProfilePictureValidationTest` (5 tests) |
| Dimension validation (100–5000 px, pixel budget) | `test_image_below_minimum_*`, `test_image_above_maximum_*`, `test_processor_enforces_pixel_budget_*`; E2E 2 (tiny.png → 422 shown in UI) |
| Image processed safely (square, 512, WEBP, payload stripped) | `test_image_is_normalised_*`, `test_polyglot_image_is_re_encoded_*` (Docker run); Docker file inspection |
| Private storage, no public URL | `ProfilePictureStorageTest::test_configured_disk_is_private`, `test_pictures_are_not_reachable_through_the_public_storage_url`; E2E 3 anonymous 401 / `/storage/...` 404 |
| DB stores only a safe reference | `users.profile_picture_path` = `profile-pictures/{id}/{uuid}.webp`; `profile_picture_path` hidden from every payload (`assertJsonMissingPath`, `not.toContain('profile-pictures/')` in E2E) |
| Replace (new stored → DB → old deleted; version bump) | `ProfilePictureReplacementTest::test_replacing_*`; E2E 1 (src changes) |
| Remove → default avatar | `ProfilePictureDeleteTest`; E2E 1 (sidebar/header `data-state="initial"`, `profile_picture_url: null`) |
| Sidebar / header / dashboard / roster / comments avatars | Vitest layout test; E2E 1 (sidebar, header, dashboard); E2E 3 (roster `owner.profile_picture_url`) |
| State updates without reload | `AuthContext.updateUser`; Settings integration test; E2E 1 observes sidebar `<img>` right after the POST response |
| Cache invalidation | `?v=<profile_picture_updated_at>`; replacement test asserts URL change; `Cache-Control: private` |
| IDOR / unauthorised modification | `ProfilePictureAuthorizationTest` (forged `user_id`, DELETE on `/users/{id}` → 405, stranger view → 403, pending/revoked → 403, admin → 200); E2E 3 |
| Rate limiting | `test_upload_and_delete_are_rate_limited_per_user` (429 with friendly message, per-user bucket) |
| Old image preserved on failures; no orphans | processing ✗, processor crash, storage `put` false, storage exception, DB saving exception — all covered in `ProfilePictureReplacementTest`; orphan sweep test |
| No credentials / sensitive data logged or returned | audit metadata assertions; error bodies checked for `/var/www`, `SQLSTATE`, `Stack trace`, `vendor/` |
| Accessibility | labelled controls (`aria-label="Change profile picture"` etc.), `role=status/alert`, `role=img` fallback, decorative avatars hidden; axe scan of the uploader in E2E 1 → 0 violations |
| Docker | image rebuilt with GD (JPEG+WEBP) + exif; migration applied; real avatar produced by E2E |
| Documentation | `docs/PROFILE_PICTURE_SYSTEM.md`, `docs/PROFILE_PICTURE_SECURITY.md`, this report, `docs/API.md` updated |

## 3. Failure-mode matrix (executed)

| Scenario | HTTP | DB | Storage | Audit |
|----------|------|----|---------|-------|
| Invalid type / size / dimensions | 422 | unchanged | nothing written | — (rejected by FormRequest) |
| Processor content rejection | 422 | unchanged | nothing written | `UPLOAD_FAILED reason=mime_mismatch` |
| Processor failure / crash | 422 (user-safe text) | unchanged | nothing written | `UPLOAD_FAILED reason=processing_failed|processing_error` |
| GD missing, strict mode | 503 | unchanged | nothing written | `UPLOAD_FAILED reason=processing_unavailable` |
| Storage `put` false / exception | 503 | unchanged | nothing written | `UPLOAD_FAILED reason=storage_failed` |
| Database save exception | 503 | unchanged | new file deleted, old file kept | `UPLOAD_FAILED reason=database_failed` |
| Success (first / replace) | 200 | path + updated_at | exactly one file in user folder | `UPLOADED` / `REPLACED` |
| Remove (file present / already missing / none) | 200 | null | folder removed | `REMOVED had_file=true|false` / none |

## 4. Environment notes

* Host PHP (XAMPP) has no GD: host runs exercise the passthrough branch; GD normalisation is verified inside Docker.
* Dev image (`backend/Dockerfile`) and prod image (`backend/Dockerfile.prod`) now compile `gd` with `--with-jpeg --with-webp` and install `exif`. Rebuild is required after pulling this branch.
* Playwright against `php artisan serve` shows the usual first-request cold latency; no test relies on transient toasts.

## 5. Remaining issues / follow-ups

* No cropping UI (deliberately omitted — no image library in the project; server centre-crops to square).
* GIF is not accepted (static formats only, by design).
* A periodic sweep command for orphaned avatar folders of deleted users is not included; folders are cleaned on the owner's next upload/remove. Consider adding to `facultylens:integrity-check` if user deletion is introduced.

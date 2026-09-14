# FacultyLens — Profile Picture Security

Threat model and controls for faculty avatars. Companion to [PROFILE_PICTURE_SYSTEM.md](PROFILE_PICTURE_SYSTEM.md).

## Principles

* **Private by default.** Images live on the private `local` disk (`storage/app/private`) and are only ever served
  through `auth:sanctum` API endpoints. No `/storage/...` URL, no public disk, no symlink.
* **Server derives the subject.** Every mutation acts on `$request->user()`. `user_id`/`id` in the payload is ignored.
* **Never trust the upload.** MIME is sniffed from bytes, the image header is decoded, dimensions and pixel counts
  are bounded, and the pixels are re-encoded before storage. Filenames are generated (`uuid.webp`).
* **Fail closed, keep the old picture.** Any failure before the database commit leaves the user's previous picture
  and reference untouched; a database failure removes the freshly written file.

## Threats and controls

| Threat | Control | Test |
|--------|---------|------|
| Fake extension / fake MIME (`.jpg` containing PHP or HTML) | `mimetypes` rule (finfo), `extensions` rule, processor requires finfo MIME == `getimagesize` type, both in allow-list | `ProfilePictureValidationTest::test_fake_jpeg_*`, `test_processor_rejects_content_*` |
| SVG / HTML / script uploads (stored XSS) | SVG not in allow-list regardless of declared type | `test_svg_is_rejected` |
| Executable / archive / double extension (`avatar.png.exe`, `avatar.php`) | `extensions:jpg,jpeg,png,webp`; Laravel also blocks PHP-ish extensions | `test_extension_must_match_an_allowed_image_type` |
| Polyglot image (valid pixels + appended payload) | GD re-encodes pixels only → payload discarded; served with `nosniff` and image `Content-Type` | `ProfilePictureSecurityTest::test_polyglot_*` |
| Decompression bomb / resource exhaustion | `max:5120` KB, `dimensions` 100–5000 px, `max_pixels` 25 M, memory-budget check before `imagecreatefromstring` | `test_image_above_maximum_dimensions_*`, `test_processor_enforces_pixel_budget_*` |
| Path traversal / filename injection | Client filename never used; stored name is `Str::uuid()`; `isOwnedPath()` requires prefix `profile-pictures/{id}/`, rejects `..`, NUL, foreign folders and non-image extensions before streaming | `test_original_filename_never_influences_*`, `test_tampered_reference_with_path_traversal_*`, `test_reference_pointing_into_another_users_folder_*` |
| IDOR — replace/delete another user's picture | Subject is the session user; `UserPolicy::updateProfilePicture/deleteProfilePicture` are self-only; no route accepts a target id for mutation (405 on `DELETE /users/{id}/profile-picture`) | `ProfilePictureAuthorizationTest::test_user_cannot_replace_*`, `test_user_cannot_delete_*` |
| Unauthorised viewing | `UserPolicy::viewProfilePicture`: self, admin, or shared course (owner ↔ ACTIVE collaborator / two ACTIVE collaborators). PENDING/REVOKED grant nothing. Anonymous → 401 | `test_unrelated_user_cannot_view_*`, `test_pending_or_revoked_*`, `test_unauthenticated_request_*` |
| Public exposure of storage | Private disk; `/storage/{path}` and `/{path}` return 403/404 and never the bytes | `test_pictures_are_not_reachable_through_the_public_storage_url` |
| Information leakage in responses | `profile_picture_path` is `$hidden`; payloads built from `profilePayload()`/`refPayload()`; exceptions mapped to fixed user-safe messages; headers contain no paths | `test_current_user_endpoints_expose_only_*`, `test_unexpected_processor_crash_*`, `test_image_response_never_exposes_storage_details` |
| Privilege escalation via multipart fields | Only `profile_picture` is read; `role`, `password`, `email` in the form are ignored | `test_profile_picture_upload_does_not_alter_credentials_or_role` |
| Upload abuse / DoS | `throttle:profile-picture` — 10 changes/hour/user (POST + DELETE share one bucket), viewing unthrottled beyond `api` | `test_upload_and_delete_are_rate_limited_per_user` |
| Stale / orphaned files | Per-user folder swept on every replace; folder removed on delete; missing file → 404 + warning log, DB stays consistent | `ProfilePictureReplacementTest::test_orphaned_files_*`, `ProfilePictureStorageTest::test_database_reference_always_matches_*` |
| Partial failure | processing ✗ → nothing written; storage ✗ → DB untouched; DB ✗ → new file deleted; old file deleted only after commit | `ProfilePictureReplacementTest::test_*_failure_*` |
| Cache poisoning / stale avatars | `Cache-Control: private`; URL versioned with `profile_picture_updated_at` | `test_replacing_stores_the_new_file_then_deletes_the_old_one` (version changes) |

## Logging & audit

`AuditLogService` records `PROFILE_PICTURE_UPLOADED | REPLACED | REMOVED | UPLOAD_FAILED` with metadata limited to
MIME, byte sizes, dimensions, output format, normalisation flag and (for failures) a `reason` code. No paths, no
binaries, no credentials. Operational logs (`Log::error/warning`) contain exception messages for operators only and
are never echoed to clients.

## Notifications

Avatar changes are **not** security-sensitive and do not emit notifications or e-mails (STEP 47 policy). Password
reset flows are untouched; a profile-picture upload never affects tokens or sessions.

## Production checklist

* `APP_DEBUG=false`; `PROFILE_PICTURE_REQUIRE_PROCESSING` defaults to `true` in production (GD must be present —
  both Dockerfiles install `gd` with JPEG/WEBP and `exif`).
* `PROFILE_PICTURE_DISK` must be a private disk (never `public`); the storage test asserts this.
* HTTPS terminated at nginx; `client_max_body_size 25m` ≥ 5 MB limit.
* `storage/app/private/profile-pictures` writable by the PHP user and included in backups.
* Rate limiter backed by the configured cache store (Redis in production).

## Residual risks / notes

* Without GD (development only) the validated original is stored unmodified. It is still MIME-sniffed, header-decoded,
  size- and dimension-bounded and served with `nosniff` — but EXIF metadata (e.g. GPS) is retained. Production refuses
  this mode.
* Anyone who can view a course roster can also fetch collaborators' avatars — the same population that already sees
  their names, roles and (for managers) e-mails.

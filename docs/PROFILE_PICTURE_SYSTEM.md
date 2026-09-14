# FacultyLens — Profile Picture System

Faculty members can upload, replace and remove a profile picture (avatar) from **Settings → Faculty Profile**. The
picture is validated twice, normalised server-side, stored on a **private disk**, and streamed only through
authenticated, authorised API endpoints. Everywhere FacultyLens shows a faculty identity (sidebar, header, dashboard,
collaborator roster, comments) the same `ProfilePicture` component renders the image or an initial fallback.

See also: [PROFILE_PICTURE_SECURITY.md](PROFILE_PICTURE_SECURITY.md), [PROFILE_PICTURE_VALIDATION_REPORT.md](PROFILE_PICTURE_VALIDATION_REPORT.md).

---

## 1. Architecture

```text
Faculty (Settings page)
   ↓ select file → client validation (type/size) → local preview → "Save photo"
POST /api/profile/picture  (multipart, field profile_picture)      ← Sanctum session + throttle:profile-picture
   ↓ UploadProfilePictureRequest      (size, sniffed MIME, extension, dimensions)
   ↓ ProfilePictureService::upload
        ↓ ProfileImageProcessor::inspect   (finfo + getimagesize agree, min/max px, pixel budget, memory budget)
        ↓ ProfileImageProcessor::process   (GD: EXIF orientation → centre square crop → 512×512 → WEBP)
        ↓ Storage::disk(local)->put(profile-pictures/{user_id}/{uuid}.webp)
        ↓ users.profile_picture_path / profile_picture_updated_at   (DB transaction)
        ↓ delete previous file(s) in the user's folder
        ↓ AuditLogService PROFILE_PICTURE_UPLOADED | REPLACED
   ↓ JSON { user: { …, profile_picture_url: "/api/users/{id}/profile-picture?v=<ts>" } }
AuthContext.updateUser(user)  →  Sidebar / header / dashboard / roster re-render (no reload)
<img src="{BACKEND_URL}/api/users/{id}/profile-picture?v=…">      ← GET streams from private storage (policy-gated)
```

The target account for every mutation is **always** `$request->user()`; no user id is ever read from the payload.

## 2. Database

Migration `2026_09_20_100001_add_profile_picture_to_users_table.php`:

| column                        | type                | notes                                                     |
|-------------------------------|---------------------|-----------------------------------------------------------|
| `profile_picture_path`        | string, nullable    | storage reference only (`profile-pictures/{id}/{uuid}.webp`), never binary |
| `profile_picture_updated_at`  | timestamp, nullable | version token for cache-busting                            |

`User` hides `profile_picture_path` and appends the computed `profile_picture_url`. `User::profilePayload()` is the
single safe account payload used by `/api/auth/*`, `/api/profile*` and the picture endpoints; `User::refPayload()`
(`id`, `name`, `profile_picture_url`) is used for embedded references (comment authors, collaborators, course owner).

## 3. Storage

Configured in `config/profile_picture.php`:

| key                         | default                          | env                                  |
|-----------------------------|----------------------------------|--------------------------------------|
| `disk`                      | `local` (private, `storage/app/private`) | `PROFILE_PICTURE_DISK`        |
| `directory`                 | `profile-pictures`               |                                      |
| `max_size_mb`               | 5                                | `PROFILE_PICTURE_MAX_SIZE_MB`        |
| `allowed_mime_types`        | jpeg, png, webp                  |                                      |
| `min_dimension` / `max_dimension` | 100 / 5000 px              |                                      |
| `max_pixels`                | 25 000 000                       |                                      |
| `output_size` / `output_format` / `output_quality` | 512 / webp / 85 |                                |
| `require_processing`        | `true` in production             | `PROFILE_PICTURE_REQUIRE_PROCESSING` |
| `rate_limit_per_hour`       | 10                               | `PROFILE_PICTURE_RATE_LIMIT_PER_HOUR`|
| `cache_max_age_seconds`     | 86400                            | `PROFILE_PICTURE_CACHE_MAX_AGE`      |

Layout: `storage/app/private/profile-pictures/{user_id}/{uuid}.webp`. One folder per user; on replace every other
file in that folder is swept, on remove the folder is deleted. The `public` disk and `/storage/...` URLs are never used.

## 4. API

| Method | Path                                | Auth            | Throttle                   | Purpose |
|--------|-------------------------------------|-----------------|----------------------------|---------|
| GET    | `/api/profile`                      | Sanctum         | api                        | current account (`user` payload) |
| PUT/PATCH | `/api/profile`                   | Sanctum         | api                        | update name/department/designation (alias of `PATCH /api/auth/user`) |
| POST   | `/api/profile/picture`              | Sanctum         | `profile-picture` 10/hour  | upload or replace (`multipart/form-data`, field `profile_picture`) |
| DELETE | `/api/profile/picture`              | Sanctum         | `profile-picture` 10/hour  | remove |
| GET    | `/api/profile/picture`              | Sanctum         | api                        | stream own image |
| GET    | `/api/users/{user}/profile-picture` | Sanctum + `UserPolicy::viewProfilePicture` | api | stream another faculty member's image |

Success (`200`):

```json
{
  "status": "success",
  "message": "Profile picture updated successfully.",
  "user": {
    "id": 1, "name": "Dr. Ada Lovelace", "email": "ada@university.edu", "role": "FACULTY",
    "department": "CSE", "designation": "Assistant Professor",
    "profile_picture_url": "/api/users/1/profile-picture?v=1789469000"
  }
}
```

Errors are always JSON `{status:"error", message, errors?}`: `401` unauthenticated, `403` not allowed to view,
`404` no picture / missing file, `422` validation or content rejection, `429` rate limited, `503` processing or
storage unavailable (old picture preserved). Messages never contain paths, SQL or stack traces.

Image responses carry `Content-Type: image/webp|png`, `Content-Length`, `Cache-Control: private, max-age=…`,
`X-Content-Type-Options: nosniff`, `Content-Disposition: inline; filename="profile-picture.webp"`.

## 5. Backend components

| File | Role |
|------|------|
| `app/Services/Profile/ProfileImageProcessor.php` | content sniffing, dimension/pixel/memory guards, GD normalisation (EXIF orientation, square crop, 512×512, WEBP/PNG), passthrough when GD is absent and not required |
| `app/Services/Profile/ProfilePictureService.php` | upload/remove/resolve lifecycle, failure ordering, per-user folder cleanup, path ownership check, audit events |
| `app/Services/Profile/ProcessedImage.php`, `ProfilePictureException.php` | DTO and user-safe exception (HTTP status + reason) |
| `app/Http/Requests/UploadProfilePictureRequest.php` | `required|file|max|mimetypes|extensions|dimensions` with friendly messages |
| `app/Http/Controllers/Api/ProfilePictureController.php` | `store`, `destroy`, `show`, `showUser` |
| `app/Policies/UserPolicy.php` | `viewProfilePicture` (self / admin / shared course), `updateProfilePicture`, `deleteProfilePicture` (self only) |
| `app/Providers/AppServiceProvider.php` | `RateLimiter::for('profile-picture')` |
| `app/Models/User.php` | `profile_picture_url` accessor, `profilePayload()`, `refPayload()`, `REF_COLUMNS` |

Audit actions (metadata never includes paths, binaries or credentials): `PROFILE_PICTURE_UPLOADED`,
`PROFILE_PICTURE_REPLACED`, `PROFILE_PICTURE_REMOVED`, `PROFILE_PICTURE_UPLOAD_FAILED` (`reason`), plus the existing
`PROFILE_UPDATED`.

## 6. Frontend

| File | Role |
|------|------|
| `src/components/profile/ProfilePicture.tsx` | single avatar component: image → initial → generic icon; sizes `xs…2xl`, tones, `decorative` for adjacent-name usage, `onError` fallback |
| `src/components/profile/ProfilePictureUploader.tsx` | choose → client validation → preview → Save/Cancel → upload; Remove with inline confirmation; loading, success (`role=status`) and error (`role=alert`) states; duplicate submissions blocked |
| `src/services/profileService.ts` | `getProfile`, `updateProfile`, `uploadProfilePicture`, `removeProfilePicture`, `getProfilePictureUrl`, `validateProfilePictureFile`, `resolveProfilePictureUrl` |
| `src/context/AuthContext.tsx` | `updateUser(user)` pushes the fresh payload into auth state (no reload) |
| `src/pages/Settings.tsx` | uploader inside the Faculty Profile card |
| `src/components/layout/Sidebar.tsx`, `DashboardLayout.tsx` (header account link), `src/pages/Dashboard.tsx` | avatar + name + designation |
| `src/components/collaboration/CollaboratorPanels.tsx`, `CollaborationComments.tsx` | roster / owner / comment-author avatars |
| `src/types/index.ts`, `src/types/collaboration.ts` | `profile_picture_url?: string | null` on `User` and `UserRef` |

Cache handling: the URL carries `?v=<profile_picture_updated_at>`; replacing or removing changes the URL so stale
browser caches are never shown.

## 7. Validation and image processing

Client: JPG/PNG/WEBP by MIME (or extension when the browser omits MIME), ≤ 5 MB, non-empty.
Server (FormRequest): `max:5120`, `mimetypes:image/jpeg,image/png,image/webp` (bytes, via finfo),
`extensions:jpg,jpeg,png,webp`, `dimensions:min 100×100, max 5000×5000`.
Processor: finfo MIME ∈ allow-list **and** `getimagesize` type must agree; `width*height ≤ max_pixels`; decoded
bitmap must fit in 80 % of the free PHP memory limit; then GD re-encodes the pixels (nothing from the original
container survives). Output: 512×512 WEBP (PNG if the GD build lacks WEBP).

Without GD (`require_processing=false`, development only) the validated original is stored as-is and a warning is
logged; in production (`require_processing=true`) the upload is refused with `503`.

## 8. Testing

Backend: `tests/Feature/Profile/*` (Upload, Validation, Authorization, Delete, Replacement, Security, Storage — 63
tests) with fixtures in `tests/Fixtures/profile-pictures/` (regenerate with `generate.py`, needs Pillow).
Run: `cd backend && php artisan test tests/Feature/Profile` (host) or
`docker compose exec -T app php artisan test tests/Feature/Profile` (GD path).

Frontend: `src/tests/components/profile.test.tsx` (Vitest + RTL, 20 tests). Run `npm run test`.

E2E: `tests/e2e/12-profile-picture.spec.ts` (Playwright, live Docker stack). Run
`npm run test:e2e -- tests/e2e/12-profile-picture.spec.ts`.

## 9. Deployment

* Both Docker images now build GD with JPEG + WEBP and `exif` (`backend/Dockerfile`, `backend/Dockerfile.prod`).
  Rebuild: `docker compose build app queue-worker && docker compose up -d`.
* Run `php artisan migrate --force`.
* Ensure `storage/app/private` is writable by the PHP user (`chown -R www-data storage`), persisted on a volume, and
  included in backups (`scripts/backup.sh` already archives `storage/app`).
* `upload_max_filesize`/`post_max_size` (25M/26M) and nginx `client_max_body_size 25m` already exceed the 5 MB limit.
* Optional env: `PROFILE_PICTURE_MAX_SIZE_MB`, `PROFILE_PICTURE_RATE_LIMIT_PER_HOUR`, `PROFILE_PICTURE_DISK` (must be a private disk).

## 10. Troubleshooting

| Symptom | Cause / fix |
|---------|-------------|
| `503 Profile pictures cannot be processed right now` | GD missing while `require_processing=true` — rebuild the image (`php -m` must list `gd`) |
| Upload succeeds but avatar shows the initial | `<img>` request denied (401/403) — cookies/`Referer` not reaching Laravel; confirm `SANCTUM_STATEFUL_DOMAINS` contains the frontend host, or the file is missing (`404`, see logs "points to a missing file") |
| `429` on upload | 10 changes/hour/user — adjust `PROFILE_PICTURE_RATE_LIMIT_PER_HOUR` |
| Old avatar still visible after replace | `profile_picture_url` must change (`?v=`); make sure the client uses the returned `user` payload (`updateUser`) |
| Orphan files under `profile-pictures/{id}` | swept automatically on the next upload/remove of that user; safe to delete manually — never delete other users' folders |

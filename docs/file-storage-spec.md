
# File storage spec (www application)

## Core design

All uploaded binary content (profile photos, club photo/hero images, event images, site logo)
lives in a single database table called `public_files`. A "file_id" anywhere in the app is
simply an integer primary key into this table. Files are **public by design** (no authorization
on reads), **immutable once stored** (you never update a row's data — you insert a new row and
repoint the reference), and served either from a dynamic PHP endpoint or a lazily-written
on-disk cache.

## The `public_files` table

```sql
CREATE TABLE public_files (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  data                LONGBLOB NOT NULL,          -- raw file bytes stored in the DB
  content_type        VARCHAR(100) DEFAULT NULL,  -- MIME type, e.g. 'image/jpeg'
  original_filename   VARCHAR(255) DEFAULT NULL,
  byte_length         INT UNSIGNED DEFAULT NULL,
  sha256              CHAR(64) DEFAULT NULL,      -- computed at insert (indexed; useful for dedup/integrity)
  created_by_user_id  INT DEFAULT NULL,           -- FK to users; NULL for system uploads
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_pf_sha256     ON public_files(sha256);
CREATE INDEX idx_pf_created_by ON public_files(created_by_user_id);
CREATE INDEX idx_pf_created_at ON public_files(created_at);
```

Key decision: the bytes go **in the database** (LONGBLOB), not on a filesystem or S3. Disk is
used only as a derived cache that can be blown away at any time.

## How domain objects reference files

Domain tables store nullable integer columns named `*_public_file_id` with foreign keys to
`public_files(id)` and `ON DELETE SET NULL`:

- `users.photo_public_file_id`
- `clubs.photo_public_file_id`, `clubs.hero_public_file_id`
- `events.photo_public_file_id`
- `club_applications.photo_public_file_id`, `club_applications.hero_public_file_id`
- a `site_logo_file_id` settings row

So a file is context-free blob storage; meaning comes entirely from which column points at it.
Replacing an image means inserting a new `public_files` row and updating the pointer — old rows
are simply orphaned (no cleanup job exists; the FK's `ON DELETE SET NULL` protects referents if
a file row is ever deleted manually).

## Write path (upload)

The single low-level writer is:

    Files::insertPublicFile($data, $contentType, $originalFilename, $createdByUserId): int

It computes `sha256` and `byte_length`, inserts, and returns the new integer id. Everything
else funnels into it:

1. **Multipart uploads** — `savePhotoFromUpload($_FILES['image'], $filename, $userId)`:
   checks `UPLOAD_ERR_OK`, sniffs the real MIME with `mime_content_type()` (not the
   client-supplied one), allows only `image/jpeg|png|webp|gif`, reads the bytes, inserts.
2. **Base64 / data-URLs** (from browser canvas croppers) — `ImageManager::storeBase64Image()`:
   strips the `data:<mime>;base64,` prefix, strict-decodes, then delegates to `storeImage()`
   which enforces an **8 MB max** and the same MIME allowlist, and writes an activity-log
   entry with the actor.

API upload endpoints (`upload_my_photo.php`, `upload_club_image.php`,
`upload_application_image.php`) take a multipart `image` field, store it via the path above,
attach the id to the owning entity (e.g. `setProfilePhoto($ctx, $fileId)`), and return
`{ file_id, url }` so the client can render immediately.

Lifecycle rule: AI-generated images are returned to the client as inline base64 and do **not**
create a `public_files` row; the client re-uploads the final cropped result through the normal
endpoint. This avoids orphan rows for images the user never saves.

## Read path (two tiers)

**Tier 1 — dynamic endpoint:** `GET /render_image.php?id=<file_id>`. No auth. Fetches
`content_type, data` from the DB and echoes it with:

    Content-Type: <stored mime>
    Content-Length: <len>
    Cache-Control: public, max-age=2592000, immutable   (30 days — files never change)
    X-Content-Type-Options: nosniff

400 on missing/non-positive id, 404 if no row.

**Tier 2 — on-disk static cache:** `Files::publicFileUrl($fileId)` is what server-rendered
HTML should call. It:

1. Looks up the file's metadata; maps content-type (falling back to the original filename's
   extension) to a cacheable extension: `.jpg .png .webp .gif .pdf`. Non-cacheable types get
   the dynamic URL `/render_image.php?id=N`.
2. Computes the cache path `cache/public/{first hex char of md5(id)}/{md5(id)}.{ext}` — the
   one-char shard keeps directories small.
3. If the cached file exists, returns its static URL (`/cache/public/a/abc….jpg`) so the web
   server serves it with zero PHP.
4. Otherwise writes it: fetch blob → write to a `.tmp<random>` file with `LOCK_EX` →
   `chmod 0644` → atomic `rename()` into place. Any failure falls back to the dynamic URL.
   All errors degrade gracefully to `/render_image.php?id=N`.

So the cache is write-through-on-first-read and safe to delete wholesale.

**API responses** don't use the disk cache — serializers emit paired fields, e.g.
`photo_file_id: 456` plus `photo_url: "/render_image.php?id=456"`, and clients just load the
URL (HTTP caching does the rest via the `immutable` header).

## Spec summary for a new application

1. One `public_files` table: `id, data BLOB, content_type, original_filename, byte_length,
   sha256, created_by_user_id, created_at`. A `file_id` is that integer id.
2. Files are public, immutable, and context-free; domain tables hold nullable `*_file_id`
   FK columns with `ON DELETE SET NULL`, and "changing an image" = insert new row + repoint.
3. One insert function computes hash/length; all upload paths (multipart, base64) validate
   server-sniffed MIME against an allowlist (jpeg/png/webp/gif) and a size cap (8 MB) before
   calling it.
4. Reads: an unauthenticated `render_image?id=N` endpoint with 30-day immutable cache headers,
   plus an optional md5-sharded on-disk cache written atomically on first URL resolution, with
   graceful fallback to the dynamic endpoint on any failure.
5. APIs always return both the raw `*_file_id` and a ready-to-use `*_url`; upload endpoints
   return `{ file_id, url }`.

## Caveats when porting

- Blobs-in-DB is simple and transactional but grows the database and ties file throughput to
  DB throughput (fine at small scale; at larger scale, swap `data` for object-storage keys and
  keep everything else identical).
- There is no garbage collection for orphaned rows.
- The sha256 index enables dedup, but the app doesn't currently do it.

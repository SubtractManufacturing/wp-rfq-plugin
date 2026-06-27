# S3 PUT Validation Spike Report

**Date:** 2026-06-26  
**Bucket:** Supabase S3-compatible storage (`rfq-intake` dev bucket)  
**Endpoint:** Path-style (`use_path_style_endpoint=true` in `RFQ_S3_Client`)  
**Scripts:** `npm run qa:s3-upload`, `bash scripts/qa-s3-spike.sh`  
**Environment:** wp-env dev site (`http://localhost:8888`), credentials from `config/dev.env.local`

## Summary

Browser direct PUT from the WordPress form origin is **viable** against the Supabase dev bucket. Presigned PUT happy path, CORS preflight, drawing MIME uploads, and origin-scoped PUT all pass. Content-Type binding is **not enforced at PUT time** by Supabase; oversize files are **not blocked at PUT time** — both are enforced at submit via `HeadObject` in `RFQ_Receipt_Service`.

No presign implementation changes required for M4 frontend work.

---

## §5.1 Matrix (TESTING.md)

| Check | Method | Pass / fail | Notes |
|-------|--------|-------------|-------|
| Presigned PUT accepts part file | `npm run qa:s3-upload` | **Pass** | HTTP 200; HeadObject confirms `PlaceHolder.step` at scoped key |
| Presigned PUT accepts drawing MIME types | `qa-s3-spike.sh` §1 | **Pass** | `application/pdf` drawing upload HTTP 200 |
| Wrong Content-Type rejected or fails | `qa-s3-spike.sh` §2 | **Pass (submit-time)** | PUT with `text/plain` on octet-stream presign returned HTTP 200; Supabase does not bind Content-Type on PUT. Submit validates via HeadObject + allowed types. |
| Oversized file blocked at PUT or caught at submit | `qa-s3-spike.sh` §3 | **Pass (submit-time)** | 1 MiB PUT succeeded; presign does not attach size conditions. Submit rejects when `content_length > max_bytes` per file type. |
| Browser OPTIONS preflight succeeds | `qa-s3-spike.sh` §4 | **Pass** | OPTIONS HTTP 200; `Access-Control-Allow-Origin: *`, methods include PUT |
| Browser PUT from form origin succeeds | `qa-s3-spike.sh` §7 | **Pass** | PUT with `Origin: http://localhost:8888` HTTP 200 |
| Path-style endpoint (if required) | Code + dev bucket | **Pass** | `RFQ_S3_Client` sets `use_path_style_endpoint: true`; required for Supabase S3 gateway |
| Exact-key binding (wrong key fails) | `qa-s3-spike.sh` §5 | **Pass** | Tampered presigned URL PUT returned HTTP 500 (upload rejected) |

---

## Additional security checks (qa-s3-upload)

| Check | Result |
|-------|--------|
| No REST delete route for upload-urls | HTTP 404 |
| No REST delete route for draft | HTTP 404 |
| DELETE on presigned PUT URL | HTTP 403 |
| DELETE with session JWT on S3 URL | HTTP 400 |
| Response must not expose `delete_url` | Confirmed absent |

---

## Assumptions validated for React upload step

1. **CORS:** Supabase bucket allows browser OPTIONS + PUT from wp-env origin (`localhost:8888`). Production must configure CORS for the live WP site origin before go-live.
2. **Content-Type header:** Client must send the same `Content-Type` used when requesting the presigned URL (best practice); provider does not reject mismatches at PUT.
3. **Progress / retry:** Failed PUTs surface as HTTP errors; frontend retry UX (AC-WP-003) is separate work.
4. **Private bucket:** Anonymous GET not tested here — see ops checklist (`qa-bucket-privacy.sh` when added).

---

## Presign implementation notes

- `create_presigned_put()` binds `ContentType` only; no signed metadata (avoids SignatureDoesNotMatch on browser PUT).
- Size limits documented in code comments; enforced at submit, not presign.

**No code changes required** from this spike.

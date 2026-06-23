# Product Requirements Document: RFQ Intake Flow

**Project:** Custom RFQ Intake System
**Status:** Approved for Development
**Last Updated:** 2026-06-23

---

## 1. Overview

### 1.1 Problem Statement

Customers currently submit Requests for Quote (RFQs) via an Airtable form embedded in the company WordPress site. This experience is functional but limited: it cannot be customized, does not support reactive UX (autosave, per-file upload progress, partial lead capture), and cannot be extended to support future intake requirements.

The internal ERP (a Remix application backed by Supabase Postgres and Supabase S3-compatible storage) deploys frequently — approximately 10 times per week — with several minutes of downtime per deployment. Tying the public RFQ intake flow to ERP availability would risk losing customer submissions during deployments.

### 1.2 Goals

- Deliver a polished, fully custom RFQ submission experience on the public WordPress site.
- Guarantee that no submitted RFQ is ever silently lost.
- Guarantee that no customer who completes submission ever sees a success state unless a durable receipt has been written.
- Capture partial/warm leads when customers begin but do not complete an RFQ.
- Decouple the public intake layer from ERP deployment cycles.
- Maintain the existing Airtable form as a hot fallback.

### 1.3 Non-Goals

- Multi-cloud or high-availability infrastructure (this is a single-region deployment).
- Cross-device RFQ draft resumption (V1 scope only covers in-session resumption).
- Real-time ERP status syncing or webhooks from WordPress to ERP.
- Customer accounts or authenticated sessions (intake is anonymous).

---

## 2. System Architecture Summary

```
┌─────────────────────────────────────────────────────────┐
│                  Public WordPress Site                   │
│                                                         │
│  ┌──────────────────────────────────────────────────┐  │
│  │           WordPress RFQ Plugin (PHP)              │  │
│  │                                                   │  │
│  │  - Serves React RFQ form via WP REST              │  │
│  │  - Issues session tokens (JWT)                    │  │
│  │  - Generates pre-signed S3 upload URLs            │  │
│  │  - Persists warm leads and drafts (WP DB)         │  │
│  │  - Validates manifests and writes receipts        │  │
│  │  - Writes receipt index row (WP DB)               │  │
│  └──────────────────────────────────────────────────┘  │
│                          │                              │
│                  WP REST API calls                      │
│                          │                              │
│  ┌──────────────────────────────────────────────────┐  │
│  │            React RFQ Form (JS bundle)             │  │
│  │  Served by WP plugin, runs in browser             │  │
│  └──────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────┘
                           │
              Direct browser → S3 uploads
              (pre-signed PUT URLs)
                           │
                           ▼
┌─────────────────────────────────────────────────────────┐
│            Supabase S3-Compatible Storage                │
│                                                         │
│  intake/                                                │
│    {session_id}/                                        │
│      meta/                                              │
│        draft.json      ← autosaved metadata             │
│        manifest.json   ← written on final submit        │
│        receipt.json    ← written after validation       │
│      parts/            ← CAD/STEP/SolidWorks files      │
│      drawings/         ← PDFs, PNGs, JPEGs              │
└─────────────────────────────────────────────────────────┘
                           │
              ERP async import worker (polling)
                           │
                           ▼
┌─────────────────────────────────────────────────────────┐
│              Internal ERP (Remix + Supabase)            │
│                                                         │
│  - Polls for new receipts (WP DB or S3 prefix scan)     │
│  - Creates official quote record and quote number       │
│  - Moves files to canonical storage location            │
│  - Writes all final Postgres records                    │
│  - Updates receipt status                               │
└─────────────────────────────────────────────────────────┘
```

---

## 3. Detailed Requirements

### 3.1 WordPress Plugin

The WordPress plugin is the public RFQ intake API. It must be deployable independently of the ERP on a controlled, infrequent release schedule.

#### 3.1.1 REST Endpoints

All endpoints are namespaced under `/wp-json/rfq/v1/`.

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/health` | None | Health check. Returns `200 OK` with `{ "status": "ok" }` if the plugin is operational and can reach S3. Used by the React form to decide whether to render the custom form or the Airtable fallback. |
| `POST` | `/sessions` | None | Creates a new intake session. Returns a signed JWT and a `session_id`. |
| `PATCH` | `/sessions/{session_id}/lead` | JWT | Upserts the warm lead record (name, email, company). Must succeed before file uploads are permitted. |
| `POST` | `/sessions/{session_id}/upload-urls` | JWT | Requests one or more pre-signed S3 PUT URLs. Returns server-generated file keys alongside each URL. Client must use the returned keys verbatim. |
| `PUT` | `/sessions/{session_id}/draft` | JWT | Autosaves the current metadata state. Writes to WP DB; also writes `meta/draft.json` to S3. |
| `POST` | `/sessions/{session_id}/submit` | JWT | Accepts the final manifest. Runs full validation, confirms S3 objects exist, writes `manifest.json` and `receipt.json` to S3, writes the receipt index row to WP DB, and returns the receipt number. |
| `GET` | `/receipts/{receipt_number}` | Internal key | Allows the ERP import worker to mark a receipt as imported. |

#### 3.1.2 Session Token Design

- On `POST /sessions`, generate a cryptographically random UUID v4 as the `session_id`.
- Sign a JWT with the following claims:

```json
{
  "sub": "rfq-session",
  "session_id": "<uuid>",
  "iat": <unix timestamp>,
  "exp": <iat + 3600>
}
```

- Sign with `HS256` using a secret stored in WP options (set at plugin install, not hardcoded).
- JWT expiry is **1 hour**. The React form must silently refresh the JWT before expiry using `POST /sessions/{session_id}/refresh` (returns a new JWT with a fresh `exp`; requires the old valid JWT as Bearer token). Refresh should be triggered automatically when the token has less than 10 minutes remaining.
- JWTs are kept in **memory only** in the React application (never `localStorage` or `sessionStorage`).

#### 3.1.3 Database Schema (WordPress MySQL)

**`rfq_sessions` table**

| Column | Type | Notes |
|--------|------|-------|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | PK |
| `session_id` | `CHAR(36)` | UUID, unique index |
| `status` | `ENUM('draft','submitted','imported','abandoned')` | |
| `lead_name` | `VARCHAR(255)` | Nullable until lead step |
| `lead_email` | `VARCHAR(255)` | Nullable |
| `lead_company` | `VARCHAR(255)` | Nullable |
| `receipt_number` | `VARCHAR(64)` | Nullable until submitted |
| `s3_prefix` | `VARCHAR(512)` | `intake/{session_id}/` |
| `created_at` | `DATETIME` | |
| `updated_at` | `DATETIME` | |
| `submitted_at` | `DATETIME` | Nullable |
| `imported_at` | `DATETIME` | Nullable |

#### 3.1.4 Receipt Number Format

Receipt numbers must be human-readable and sortable. Format: `RFQ-{YYYYMMDD}-{6-digit zero-padded sequence}`.
Example: `RFQ-20260623-000042`.

Sequence is a daily auto-increment stored in a separate `rfq_receipt_sequences` table keyed by date.

---

### 3.2 React RFQ Form

The form is a JavaScript bundle built with React, served by the WordPress plugin as an enqueued script. It renders into a container div on any WP page where the plugin shortcode is placed.

#### 3.2.1 Startup and Fallback Logic

On page load, the form must:

1. Call `GET /wp-json/rfq/v1/health` with a **3-second timeout**.
2. If the response is `200 OK`, render the custom React RFQ form.
3. If the response is non-200 or the request times out, render the Airtable embed iframe (existing embed code, stored as a plugin setting).

This check runs once on mount. There is no polling after the form has loaded.

#### 3.2.2 Multi-Step Form Flow

The form is divided into the following steps. Customers cannot proceed past Step 2 until Step 1 is complete. All other steps can be navigated freely once a session exists.

**Step 0 — Session Init (background, not visible to user)**
- On form mount (after successful health check), call `POST /sessions`.
- Store the returned JWT in React state. Begin the JWT refresh timer.

**Step 1 — Contact Information**
- Fields: First name, Last name, Email (required), Phone (optional), Company name (required), Job title (optional).
- On blur from the email field (or on step advance), call `PATCH /sessions/{session_id}/lead` with the current values.
- This write must succeed before the user can advance to Step 2. Show an inline error and a retry button if it fails; do not silently drop the data.
- A successful lead write means the customer is captured as a warm lead even if they abandon later.

**Step 2 — File Uploads**
- Customers can add one or more parts to the RFQ. Each part has:
  - A part file (STEP, SolidWorks `.sldprt`, `.sldasm`, `.x_t`, `.iges`, `.stl`, or other CAD formats). Required.
  - One or more drawing/supporting files (PDF, PNG, JPEG). Optional.
- Per-file upload flow:
  1. Customer selects a file.
  2. React calls `POST /sessions/{session_id}/upload-urls` with `{ file_type: "part"|"drawing", filename: "...", content_type: "..." }`.
  3. Plugin returns `{ upload_url: "...", file_key: "intake/{session_id}/parts/{file_id}_{filename}" }`.
  4. React uploads directly to S3 using the returned `upload_url` via `PUT` fetch.
  5. On successful upload (HTTP 200 from S3), React marks the file as confirmed in local state and stores the returned `file_key`.
  6. Upload progress is shown per file. Failed uploads show an inline retry button.
- Customers cannot advance to Step 3 until at least one part with a confirmed uploaded part file exists.
- File size limits (enforced via S3 policy conditions, not just client-side):
  - Part files: 500 MB max.
  - Drawing/supporting files: 50 MB max.
- Accepted MIME types (enforced via S3 policy condition):
  - Part files: `application/octet-stream` (CAD files have no standard MIME type; accept any).
  - Drawings: `application/pdf`, `image/png`, `image/jpeg`.

**Step 3 — Part Metadata**
For each uploaded part, collect:
- Material (text field with type-ahead from a predefined list, plus free-form entry).
- Tolerance range (dropdown: Standard / Precision / Custom; if Custom, a free-text field).
- Threads/features (free text, optional).
- Quantity (integer, required).
- Target unit price (currency field, optional).
- Notes (multi-line text, optional).

**Step 4 — Global RFQ Metadata**
- Required delivery date (date picker).
- Lead time preference (dropdown: Standard / Expedited / Best effort).
- Shipping destination (address fields: country, state/region, city, zip).
- Purchase order number (optional free text).
- NDA required (checkbox).
- Additional notes (multi-line text, optional).

**Step 5 — Review and Submit**
- Summary of all contact info, files, per-part metadata, and global metadata.
- Customer can navigate back to any step to edit.
- A "Submit RFQ" button triggers the final submit flow (see 3.2.3).

#### 3.2.3 Final Submit Flow

1. React collects the full manifest:

```json
{
  "session_id": "...",
  "contact": { ... },
  "parts": [
    {
      "part_file_key": "intake/{session_id}/parts/{file_id}_{filename}",
      "drawing_file_keys": ["intake/{session_id}/drawings/{file_id}_{filename}"],
      "material": "...",
      "tolerance": "...",
      "quantity": 10,
      "target_unit_price": null,
      "notes": "..."
    }
  ],
  "global": {
    "required_delivery_date": "2026-08-01",
    "lead_time_preference": "standard",
    "shipping_destination": { ... },
    "po_number": null,
    "nda_required": false,
    "notes": "..."
  }
}
```

2. React sends this manifest to `POST /sessions/{session_id}/submit`.
3. If the request succeeds and returns a `receipt_number`, React shows the success screen with the receipt number. **This is the only condition under which a success screen is shown.**
4. If the request fails (network error, non-200 response), React shows an error state with:
   - An explanation that the submission failed but their work is saved.
   - A **Retry Submission** button. The retry re-sends the same manifest; uploaded files are already in S3 and do not need to be re-uploaded.
   - A suggestion to try again in a few minutes if retries continue to fail.
5. React must **never clear uploaded file state or form data on a submit failure.**

#### 3.2.4 Autosave Behavior

- Autosave fires on:
  - 30 seconds of user inactivity (debounced from last keypress/interaction).
  - Tab/step navigation (when the user moves between steps).
- Autosave calls `PUT /sessions/{session_id}/draft` with the current metadata state (excluding file blobs).
- Autosave is fire-and-forget from the UX perspective. Failures are silently retried once; if the second attempt fails, a subtle "Draft not saved" indicator appears but does not block the user.
- In-session draft resumption: if the JWT refresh fails or the page is reloaded within the same `sessionStorage` context, the form can re-hydrate from the last autosaved state. Store `{ session_id, draft }` in `sessionStorage` for this purpose only.

---

### 3.3 S3 Storage Design

#### 3.3.1 Key Structure

```
intake/
  {session_id}/
    meta/
      draft.json
      manifest.json
      receipt.json
    parts/
      {file_id}_{sanitized_original_filename}
    drawings/
      {file_id}_{sanitized_original_filename}
```

- `file_id` is a UUID v4 generated server-side by the WordPress plugin at URL-generation time.
- `sanitized_original_filename` is the client-provided filename with non-alphanumeric characters (except `.` and `-`) replaced with `_`, truncated to 100 characters.
- **Keys are always generated server-side.** The client never supplies a key; it only receives and uses the key returned alongside the pre-signed URL.

#### 3.3.2 Pre-Signed URL Constraints

Pre-signed PUT URLs must include the following S3 policy conditions:

```json
{
  "conditions": [
    ["content-length-range", 1, 524288000],
    ["starts-with", "$key", "intake/{session_id}/"]
  ]
}
```

- URL expiry: **30 minutes** from issuance.
- Each URL is single-use (S3 enforces this natively; the URL encodes the specific key).
- Content-type conditions are set per file type (part vs. drawing), enforced at the policy level, not just client validation.

#### 3.3.3 Lifecycle Rules

Apply the following S3 lifecycle rules to the `intake/` prefix:

| Rule | Condition | Action |
|------|-----------|--------|
| Expire unconfirmed sessions | Objects under `intake/*/` where no `receipt.json` exists after 30 days | Delete |
| Archive imported sessions | Objects under `intake/*/` with status `imported` after 90 days | Move to Glacier or delete per data retention policy |

Note: The 30-day cleanup rule requires a separate cleanup process (Lambda or ERP worker) to identify session prefixes without receipts, since S3 lifecycle rules cannot natively check for the absence of a sibling object. Implement as a weekly cron job in the ERP that scans the `intake/` prefix and deletes session prefixes older than 30 days with no `receipt.json`.

---

### 3.4 Final Submit Server-Side Validation

When `POST /sessions/{session_id}/submit` is called, the WordPress plugin must execute the following sequence. Any failure at any step returns an error to the client and does not advance to the next step.

1. **Validate JWT.** Verify signature, expiry, and that `session_id` in the JWT matches the URL parameter.
2. **Validate session state.** Check the WP DB `rfq_sessions` row. If `status` is already `submitted` or `imported`, return the existing `receipt_number` (idempotent re-submission is safe).
3. **Validate manifest fields.** Check all required fields are present and valid. Return field-level errors if not.
4. **Confirm S3 objects exist.** For every `part_file_key` and `drawing_file_key` declared in the manifest, call S3 `HeadObject`. If any declared key is missing or returns an error, abort and return an error identifying which files are missing.
5. **Write `manifest.json` to S3** at `intake/{session_id}/meta/manifest.json`.
6. **Generate receipt number** using the daily sequence.
7. **Write `receipt.json` to S3** at `intake/{session_id}/meta/receipt.json`:

```json
{
  "receipt_number": "RFQ-20260623-000042",
  "session_id": "...",
  "submitted_at": "2026-06-23T14:30:00Z",
  "manifest_key": "intake/{session_id}/meta/manifest.json"
}
```

8. **Write receipt index row** to `rfq_sessions` WP DB table: set `status = 'submitted'`, `receipt_number`, `submitted_at`.
9. **Return receipt number** to the client.

**Failure handling for steps 5–8:** If step 7 or 8 fails after step 5 has succeeded, the system is in a partially-written state. The ERP import worker must treat any `manifest.json` without a corresponding `receipt.json` as incomplete and skip it. A WP admin cron job should alert on sessions where a `manifest.json` exists without a `receipt.json` after more than 15 minutes, so the operations team can investigate.

---

### 3.5 ERP Import Worker

The ERP contains an async background worker that imports completed intake packages into the production system.

#### 3.5.1 Import Trigger

The worker polls the WordPress `rfq_sessions` table (via a shared read-only DB connection or a dedicated WordPress REST endpoint secured with an internal API key) for rows with `status = 'submitted'` that have not yet been imported. Poll interval: every 5 minutes.

Alternatively, the worker can scan the S3 `intake/` prefix for `receipt.json` objects. Use whichever is easier to implement given existing ERP infrastructure. The WP DB poll is preferred as it avoids S3 LIST costs and is faster.

#### 3.5.2 Import Sequence

For each unimported receipt:

1. Read `receipt.json` from S3 to get the `manifest_key`.
2. Read `manifest.json` from S3.
3. Create the official quote record in Supabase Postgres with a new quote number.
4. Copy files from `intake/{session_id}/` to the canonical quote storage location (e.g., `quotes/{quote_number}/`). Do not delete the intake copies until the import is confirmed complete.
5. Write all part, metadata, and contact records to Postgres.
6. Mark the receipt as imported: call the WP REST endpoint `GET /wp-json/rfq/v1/receipts/{receipt_number}?action=mark_imported` with the internal API key, which sets `status = 'imported'` and `imported_at` in WP DB.
7. Optionally delete or archive the intake S3 prefix per the lifecycle policy.

#### 3.5.3 Import Idempotency

The import worker must be safe to run multiple times on the same receipt. Before creating a quote record, check whether a quote with `source_receipt_number = receipt_number` already exists. If so, skip and mark as imported (in case the WP DB update failed on a prior run).

---

### 3.6 Fallback Behavior Reference

| Failure Scenario | Expected Behavior |
|------------------|-------------------|
| WordPress down when user visits the RFQ page | Health check fails; Airtable iframe renders automatically |
| WordPress goes down mid-session | Files already uploaded to S3 are safe. Autosaved draft in `sessionStorage` allows form state to persist. User sees a submit error with retry guidance. Submit succeeds once WP recovers. |
| S3 unavailable for a file upload | Per-file upload error with inline retry button. User cannot proceed to submit until all required files are confirmed uploaded. |
| S3 unavailable at submit time | `HeadObject` check fails; WP returns an error; user sees "submission failed, your work is saved, try again." Files are already in S3 and will not need re-uploading. |
| ERP is down | No user impact. The receipt is written by WordPress. The ERP import worker will pick it up on the next poll cycle once the ERP recovers. |
| ERP import fails | Worker retries on next poll cycle. Receipt in S3 and WP DB is the recovery point. The customer already has a receipt number confirming their submission. |
| JWT expires mid-session | Silent JWT refresh (triggered at T-10 minutes before expiry) issues a new token. If the refresh itself fails, show an inline warning and pause autosave; allow the user to continue filling the form and surface the issue only if they attempt to submit. |

---

## 4. Security Requirements

### 4.1 Session Isolation

- Pre-signed upload URLs are scoped to `intake/{session_id}/` via S3 policy conditions. A user with a valid JWT for session A cannot upload to session B's prefix.
- WordPress validates the JWT on every authenticated endpoint call and confirms the `session_id` in the JWT matches the URL parameter.

### 4.2 Rate Limiting

- `POST /sessions` (session creation) must be rate-limited per IP: maximum 10 sessions per hour per IP.
- `POST /sessions/{session_id}/upload-urls` must be rate-limited per session: maximum 200 URLs per session (sufficient for any realistic RFQ).

### 4.3 File Key Generation

- File keys are **always generated server-side** by the WordPress plugin. The client supplies only the original filename (for the sanitized suffix) and the file type (part vs. drawing).
- The plugin never accepts a client-supplied S3 key.

### 4.4 Content-Type Enforcement

- S3 policy conditions enforce content-type per file category. Client-side validation is a UX convenience only.

### 4.5 Internal API Key

- The ERP import worker authenticates to WordPress REST endpoints using a static API key stored in WP options and the ERP's environment variables.
- This key must never be exposed to the browser or committed to source control.

### 4.6 JWT Secret Rotation

- The JWT signing secret is stored in WordPress options. Rotating the secret invalidates all in-flight sessions. Document the rotation procedure and perform it only during low-traffic periods.

---

## 5. Operational Requirements

### 5.1 Monitoring and Alerting

| Alert | Condition | Severity |
|-------|-----------|----------|
| Health check degraded | `GET /rfq/v1/health` returns non-200 for more than 60 seconds | High |
| Orphaned manifests | `manifest.json` exists without `receipt.json` after 15 minutes | Medium |
| Import backlog | More than 20 receipts with `status = 'submitted'` and `submitted_at` older than 1 hour | Medium |
| Session creation spike | More than 50 sessions created in a 5-minute window | Low (possible abuse) |

### 5.2 Airtable Fallback Maintenance

The Airtable embed URL and form configuration must be maintained in a WordPress plugin setting. It must not be hardcoded. The operations team must be able to update the fallback URL without a plugin deployment.

### 5.3 Data Retention

- Warm leads (sessions with `status = 'draft'`): retain WP DB rows for 90 days, then archive or delete.
- Submitted receipts: retain WP DB rows indefinitely (they are lightweight index rows).
- S3 intake objects: retain for 90 days post-import, then delete. Retain indefinitely for sessions that were never imported (possible data issue; alert and investigate).

---

## 6. Out of Scope (V1)

- Customer-facing RFQ status tracking portal.
- Email notifications to customers on receipt (may be added as a plugin extension).
- Internal ERP notifications on new RFQ imports (ERP can handle this internally once the import record is written).
- Cross-device RFQ draft resumption.
- Multi-file batch upload UI (drag-and-drop zone is fine; concurrent uploads are fine; but no zip/archive upload with server-side extraction).
- AI-assisted metadata extraction from uploaded drawings.

---

## 7. Acceptance Criteria

- A customer who completes the full RFQ form and receives a receipt number can have their submission located in the ERP within one import poll cycle (≤5 minutes).
- A customer who submits and then re-submits the same session (due to a network error causing them to retry) receives the same receipt number and does not create a duplicate quote.
- If the WordPress plugin is unreachable when a customer navigates to the RFQ page, the Airtable form renders within 3 seconds.
- If S3 upload of any file fails, the customer sees a per-file error and can retry without re-entering any form data.
- If the final submit endpoint fails after all files are uploaded, the customer can retry submission without re-uploading files.
- No RFQ with a `receipt.json` in S3 and a corresponding WP DB receipt row is ever lost due to ERP downtime.
- Session creation is rejected with HTTP 429 after 10 sessions from the same IP within one hour.
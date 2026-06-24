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
- Draft resumption after page refresh, tab close, or cross-device return.
- Real-time ERP status syncing back into WordPress (WP final state is `submitted`).
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
│  │  - Writes receipt index row (WP DB, status=submitted)│  │
│  │  - Notifies ERP via webhook (best-effort)           │  │
│  └──────────────────────────────────────────────────┘  │
│                          │                              │
│                  WP REST API calls                      │
│                          │                              │
│  ┌──────────────────────────────────────────────────┐  │
│  │     React RFQ Form (TypeScript → bundled JS/CSS)  │  │
│  │  Tailwind-styled UI; served by WP plugin          │  │
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
         ┌─────────────────┴─────────────────┐
         │                                   │
  Webhook POST (best-effort)         S3 poll (~5 min)
  WP → ERP on new receipt            ERP import worker
         │                                   │
         └─────────────────┬─────────────────┘
                           ▼
┌─────────────────────────────────────────────────────────┐
│              Internal ERP (Remix + Supabase)            │
│                                                         │
│  - Discovers new receipts via S3 (`receipt.json`)       │
│  - Webhook triggers immediate import when ERP is up     │
│  - Creates official quote record and quote number       │
│  - Copies manifest files to canonical storage           │
│  - Writes Postgres records; deletes intake prefix       │
│  - No reads or writes to WordPress after submit         │
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
| `GET` | `/health` | None | Health check. Returns `200 OK` with `{ "status": "ok" }` if the plugin is operational and can reach S3 using configured credentials (see 5.2). Returns non-200 if S3 settings are missing or connectivity fails. Used by the React form to decide whether to render the custom form or the Airtable fallback. |
| `POST` | `/sessions` | None | Creates a new intake session. Returns a signed JWT and a `session_id`. |
| `POST` | `/sessions/{session_id}/refresh` | JWT | Issues a new JWT for the same session. Requires a valid (non-expired) current JWT as Bearer token. See [Session token design](#312-session-token-design). |
| `PATCH` | `/sessions/{session_id}/lead` | JWT | Upserts the warm lead contact record. Must succeed (with required fields) before file uploads are permitted. See [Contact fields](#315-contact-fields). |
| `POST` | `/sessions/{session_id}/upload-urls` | JWT | Requests one or more pre-signed S3 PUT URLs for a specific part. Requires `part_id`, `file_type`, `filename`, and `content_type`. Returns server-generated file keys alongside each URL. Client must use the returned keys verbatim. |
| `PUT` | `/sessions/{session_id}/draft` | JWT | Autosaves the current metadata state. Writes to WP DB; also writes `meta/draft.json` to S3. |
| `POST` | `/sessions/{session_id}/submit` | JWT | Accepts the final manifest. Runs full validation, confirms S3 objects exist, writes `manifest.json` and `receipt.json` to S3, writes the receipt index row to WP DB, notifies ERP via webhook (best-effort), and returns the receipt number. |

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
- JWT expiry is **1 hour** (`exp = iat + 3600`).
- **Refresh:** `POST /sessions/{session_id}/refresh` with the current JWT as `Authorization: Bearer`. Validates signature, expiry, and that `session_id` in the JWT matches the URL. Rejects if the session is already `submitted`. Returns `{ "token": "<new-jwt>" }` with a fresh `iat`/`exp` (another 1 hour). Returns `401` if the JWT is missing, invalid, or expired.
- The React form must silently refresh when the token has **less than 10 minutes** remaining (timer from decoded `exp`). On refresh failure, show an inline warning and pause autosave; allow the user to continue editing; surface errors on upload/submit attempts.
- JWTs are kept in **memory only** in the React application (never `localStorage` or `sessionStorage`).

#### 3.1.3 Database Schema (WordPress MySQL)

**`rfq_sessions` table**

| Column | Type | Notes |
|--------|------|-------|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | PK |
| `session_id` | `CHAR(36)` | UUID, unique index |
| `status` | `ENUM('draft','submitted','abandoned')` | Final customer-facing state is `submitted`. ERP import does not change WP rows. |
| `lead_first_name` | `VARCHAR(255)` | Nullable until lead step; required for Step 1 completion |
| `lead_last_name` | `VARCHAR(255)` | Nullable until lead step; required for Step 1 completion |
| `lead_email` | `VARCHAR(255)` | Nullable until lead step; required for Step 1 completion |
| `lead_company` | `VARCHAR(255)` | Optional |
| `lead_phone` | `CHAR(10)` | Optional; 10-digit US/CA national number, digits only |
| `lead_phone_country_code` | `VARCHAR(4)` | Optional; defaults to `1` when `lead_phone` is set |
| `lead_job_title` | `VARCHAR(255)` | Optional; not collected in V1 UI (schema only) |
| `receipt_number` | `VARCHAR(64)` | Nullable until submitted |
| `s3_prefix` | `VARCHAR(512)` | `intake/{session_id}/` |
| `created_at` | `DATETIME` | |
| `updated_at` | `DATETIME` | |
| `submitted_at` | `DATETIME` | Nullable |

#### 3.1.4 Receipt Number Format

Receipt numbers must be human-readable and sortable. Format: `RFQ-{YYYYMMDD}-{6-digit zero-padded sequence}`.
Example: `RFQ-20260623-000042`.

Sequence is a daily auto-increment stored in a separate `rfq_receipt_sequences` table keyed by date.

#### 3.1.5 Contact Fields

Contact fields are **mirrored** across the warm lead (`PATCH /lead`, `rfq_sessions` columns), autosave draft, and final manifest `contact` object. Capture everything the customer provides; downstream systems can filter later.

| Field | Required | Collected in V1 UI | Notes |
|-------|----------|-------------------|-------|
| `first_name` | Yes | Yes | |
| `last_name` | Yes | Yes | |
| `email` | Yes | Yes | |
| `company` | No | Yes | |
| `phone` | No | Yes | 10-digit US/CA national number; see [Phone normalization](#315-contact-fields) |
| `phone_country_code` | No | Yes | Country calling code; `1` for US/CA in V1 UI. Schema supports future international numbers. |
| `job_title` | No | No | Nullable in schema/API/manifest only; not collected in V1 UI |

**Phone normalization (V1 — US/Canada):**

- **UI:** Optional masked input — display `(555) 555-5555` with implicit **+1** (show `+1` prefix, do not make users type formatting characters). User enters digits only via the mask.
- **Storage:** Strip formatting before API/manifest persistence. `phone` = exactly **10 digits** (national number). `phone_country_code` = `"1"` when `phone` is set; `null` when `phone` is omitted.
- **Validation:** If `phone` is provided, it must match `^[0-9]{10}$`. If `phone_country_code` is provided, it must be numeric (1–4 digits). Reject on client and server.
- **International:** V1 UI does not collect non-US/CA numbers. `phone_country_code` exists so future international support can ship without a manifest migration. See `Planning/FUTURE.md`.

**`PATCH /sessions/{session_id}/lead` body:**

```json
{
  "first_name": "Jane",
  "last_name": "Smith",
  "email": "jane@example.com",
  "company": "Acme Corp",
  "phone": "5555550100",
  "phone_country_code": "1",
  "job_title": null
}
```

Server validation: reject with field-level errors if `first_name`, `last_name`, or `email` is missing or invalid. `email` must pass standard format validation (single `@`, valid domain with TLD, no whitespace — use PHP `filter_var(FILTER_VALIDATE_EMAIL)` or equivalent). No MX lookup or disposable-domain blocking in V1. `company` and `job_title` are optional; omit or send `null`. If `phone` is provided, validate 10-digit format and set `phone_country_code` to `"1"` if omitted. If `phone` is null/omitted, `phone_country_code` must be null.

**Manifest `contact` object** uses the same shape and values as the warm lead at submit time.

#### 3.1.6 ERP Import Webhook (WordPress → ERP)

After a successful submit (receipt written to S3 and WP DB), the plugin may send a **best-effort** webhook to the ERP. This is not on the customer critical path.

- **Configuration:** ERP webhook URL and shared secret are set in the WordPress admin plugin settings (see 5.2). WordPress is managed hosting — settings are **not** read from server environment variables.
- **If webhook URL is blank:** Skip the webhook entirely. Import relies on the ERP S3 poll worker only. Use this for local/plugin testing or when pairing a WP staging site with no ERP endpoint yet.
- **Method:** `POST` to the configured ERP import webhook URL.
- **When:** After steps 5–8 of submit succeed, before or after the receipt number is returned to the client. Webhook failure must **never** fail the customer's submit response.
- **Payload:**

```json
{
  "receipt_number": "RFQ-20260623-000042",
  "session_id": "550e8400-e29b-41d4-a716-446655440000",
  "receipt_key": "intake/{session_id}/meta/receipt.json"
}
```

- **Authentication:** `X-RFQ-Signature` header — HMAC-SHA256 of the raw JSON body using the shared secret from plugin settings (must match the secret configured on the target ERP environment). Reject unsigned or invalid requests on the ERP side.
- **ERP behavior:** If online, process the receipt immediately (same sequence as 3.5.2). If offline or the request fails, no action required on the WordPress side — the ERP scheduled worker picks it up from S3.
- **V1:** Fire-and-forget single POST. No retry queue in WordPress.

WordPress has **no other communication with the ERP** after submit. The ERP does not read or write the WordPress database.

#### 3.1.7 Material Catalog

Materials are **suggestions, not constraints**. The manifest always stores the customer's final material string.

**Default catalog:** Shipped with the plugin as JSON (e.g. `materials/default.json`). Each entry:

| Field | Purpose |
|-------|---------|
| `id` | Stable key for admin overrides |
| `label` | Display name (e.g. `1018 Steel`, `6061 Aluminum`) |
| `aliases` | Optional match strings for type-ahead (e.g. `1018`, `1018 steel`) |
| `show_in_dropdown` | If true, include in the simple dropdown; if false, type-ahead only |

**Effective catalog:** At form load, the plugin merges defaults with admin overrides (see 5.2) and passes the result to the React form (e.g. via localized script config). Disabled entries are omitted. Renamed entries use the admin label. Admin-added entries are appended.

**Admin overrides (WP admin):** After install, operators can without redeploying:
- Add new material entries
- Disable entries (hidden from dropdown and type-ahead)
- Rename entries (change `label`; `id` stays stable for overrides)

Do not fetch materials from the ERP in V1.

**Example default entries (illustrative):** 1018 Steel, 6061 Aluminum, 7075 Aluminum, 304 Stainless — exact list lives in shipped JSON.

---

### 3.2 React RFQ Form

The form is a **TypeScript** React application (`.tsx` source), built with Vite into a single JS + CSS bundle and served by the WordPress plugin as enqueued scripts. There are no plain JavaScript source files in the form codebase.

**Frontend stack:**

| Layer | Choice | Notes |
|-------|--------|-------|
| Language | TypeScript | Strict typing for manifest, API responses, and multi-step form state |
| UI | React 18 | Multi-step form, in-memory session state |
| Build | Vite | Output to plugin `build/` (e.g. `rfq-form.js`, `rfq-form.css`) |
| Styling | **Tailwind CSS** | All form UI styling — layout, typography, spacing, colors, responsive behavior |

**Styling rules:**

- Use **Tailwind utility classes** for all customer-facing form UI (steps, inputs, buttons, errors, progress, success screen).
- Do **not** add separate CSS modules, styled-components, Sass/Less files, or hand-written component stylesheets.
- A single entry stylesheet (e.g. `index.css` with `@tailwind` directives) is allowed for Tailwind base/components/utilities and minimal WordPress theme isolation (e.g. scoped reset on `#rfq-form-root`).
- Inline SVG icons (e.g. success checkmark) may live in TSX; their size and color use Tailwind classes on the SVG or wrapper.

**Embedding:** Register shortcode **`[rfq_form]`**. Output a single mount point (e.g. `<div id="rfq-form-root"></div>`). Enqueue the built bundle only on pages where the shortcode is present.

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
- Fields: First name (required), Last name (required), Email (required, `type="email"` with standard format validation), Company name (optional), Phone (optional, US/Canada — masked `(555) 555-5555` with `+1` prefix shown in UI).
- Phone UI uses an input mask so users enter digits only; formatting is applied automatically. On submit to API, send normalized `phone` (10 digits) and `phone_country_code` (`"1"`).
- Do not render a job title field in V1. The API and DB accept `job_title`; always send `null` from the client.
- On blur from the email field (or on step advance), call `PATCH /sessions/{session_id}/lead` with all current contact values.
- Step 1 is complete when required fields (`first_name`, `last_name`, `email`) are present and the lead write succeeds. Optional fields are included when provided.
- This write must succeed before the user can advance to Step 2. Show an inline error and a retry button if it fails; do not silently drop the data.
- A successful lead write means the customer is captured as a warm lead even if they abandon later.

**Step 2 — File Uploads (part-scoped)**

Uploads are organized **per part row**, not in a shared pool. Each part row owns its files from selection through submit.

- Customers click **Add part** to create a part row. The client assigns a `part_id` (UUID v4) per row; this ID is stable for the lifetime of the row in the session.
- Each part row contains:
  - One part file picker (STEP, SolidWorks `.sldprt`, `.sldasm`, `.x_t`, `.iges`, `.stl`, or other CAD formats). **Required** before the row counts toward Step 2 completion.
  - One or more drawing/supporting file pickers (PDF, PNG, JPEG). **Optional**; drawings belong to the part row they are added under.
- Step 3 metadata is collected for the **same part rows** (matched by `part_id`). Step 3 does not reassign files between parts.
- Per-file upload flow:
  1. Customer selects a file within a part row.
  2. React calls `POST /sessions/{session_id}/upload-urls` with `{ part_id, file_type: "part"|"drawing", filename, content_type }`.
  3. Plugin returns `{ upload_url, file_key }` where `file_key` is under `intake/{session_id}/parts/` or `.../drawings/` depending on `file_type`.
  4. React uploads directly to S3 using the returned `upload_url` via `PUT` fetch.
  5. On successful upload (HTTP 200 from S3), React marks the file as confirmed in that part row's local state and stores the returned `file_key`.
  6. Upload progress is shown per file. Failed uploads show an inline retry button.
- Customers cannot advance to Step 3 until at least one part row has a confirmed uploaded part file.
- **Part count limit:** Maximum **20 part rows** per RFQ (soft cap). When the limit is reached, disable **Add part** and show helper text directing the customer to email larger RFQs to the international RFQ email (see 5.2). Enforce the same limit server-side on submit.
- **Implementation:** Define the limit as a single named constant in plugin code (e.g. `RFQ_MAX_PARTS = 20`) referenced by both React config and submit validation — not magic numbers scattered in the codebase. Not editable in WP admin in V1; structure so a future admin setting can override the constant.
- Customers may **remove a part row** or **replace files** in the UI at any time before submit. This updates client state only — it does **not** delete objects from S3.
- The **manifest** is the authoritative list of files for the RFQ. Extra objects in the session's S3 prefix (from removed rows, replaced files, or duplicate uploads) are ignored at submit.
- Replacing a part file or adding/removing drawing files in a row issues new upload-url requests as needed; keys stay scoped to that row's `part_id` in client state until submit.
- File size limits (enforced via S3 policy conditions, not just client-side):
  - Part files: 500 MB max.
  - Drawing/supporting files: 50 MB max.
- Accepted MIME types (enforced via S3 policy condition):
  - Part files: `application/octet-stream` (CAD files have no standard MIME type; accept any).
  - Drawings: `application/pdf`, `image/png`, `image/jpeg`.

**Step 3 — Part Metadata**

For each part row from Step 2 (same `part_id`), collect:

- **Material** (required, non-empty) — dual UX, always stored as a free-text string in the manifest:
  - **Simple dropdown** — short list of common options (e.g. general-purpose aluminum, common steels) for customers who do not know a specific alloy. Selecting an option fills the material field.
  - **Type-ahead text field** — matches the effective material catalog (label and aliases). Example: typing `1018` suggests and can select `1018 Steel`.
  - **Custom entry** — user may ignore suggestions and submit any string (exotic alloy, customer-supplied material, etc.). Never block submit for a material not in the catalog.
  - Catalog source: see [Material catalog](#317-material-catalog).
- **Tolerance** (required) — dropdown: `standard`, `precision`, or `custom`. If `custom`, a free-text **tolerance detail** field is required (non-empty). Omit or send `null` for detail when not custom.
- Threads/features (free text, optional).
- Quantity (integer, required, minimum **1**; no maximum — large production quantities are valid).
- Target unit price (optional) — customer's target price **per part** in **USD**. Positive number, up to 2 decimal places; stored in manifest as a number or `null`. Display with helper text that this is optional and helps you understand their budget. Reject negative values and non-numeric input on submit.
- Notes (multi-line text, optional).

**Step 4 — Global RFQ Metadata**

V1 online intake assumes **shipping within the US and Canada only**.

- **International notice (visible on the form):** Display helper text that orders outside North America should be emailed to the address configured in plugin settings (see 5.2). Show this on Step 4 (and optionally near the top of the form). The email address must be rendered from settings — not hardcoded.
- **Required delivery date** (date picker) — the customer's requested need-by date for quoting purposes, not a contractual commitment. Must be **today or later** (validate on client and server; use UTC for server-side date comparison). No maximum date.
- **Lead time preference** (dropdown, required) — how the customer wants timing prioritized relative to that date. Stored in manifest as lowercase enum (see below). UI labels may be friendlier.

| Manifest value | UI label | Meaning |
|----------------|----------|---------|
| `no_rush` | No rush | Flexible timing; no pressure to hit the requested date |
| `standard` | Standard | Normal shop lead time |
| `target_date` | Meet target date | Plan to the requested delivery date above |
| `expedited` | Expedited | Faster than standard if feasible |
| `economy` | Economy | Prefer lower cost; longer lead time acceptable |

Server validation: `global.lead_time_preference` must be one of the values above.
- **Shipping postal code** (single field, required) — US ZIP (5 or 9 digit) or Canadian postal code (`A1A 1A1`). No country/state/city fields in V1.
- Purchase order number (optional free text).
- **Treat as NDA** (checkbox, optional, default unchecked):
  - **Label / description:** *Treat as NDA — These parts will be completely excluded from posting on social media or being used in any marketing materials.*
  - **When checked:** Set `global.nda_required: true` in the manifest. Show an inline notice below the checkbox: *If you require a formal NDA signed by both parties before sharing any IP, please email {{sales_contact_email}}.* The email address comes from plugin settings (see 5.2). Display only — the plugin does not send email or generate NDA documents.
  - **When unchecked:** `nda_required: false`.
  - **Intent:** This flag tells internal teams to handle the quote with marketing restrictions. It is **not** a request to receive a countersigned NDA through the online form. Customers who need a formal NDA before sharing files should contact sales directly — they may do so without completing an RFQ upload.
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
  "contact": {
    "first_name": "Jane",
    "last_name": "Smith",
    "email": "jane@example.com",
    "company": "Acme Corp",
    "phone": "5555550100",
    "phone_country_code": "1",
    "job_title": null
  },
  "parts": [
    {
      "part_id": "550e8400-e29b-41d4-a716-446655440000",
      "part_file_key": "intake/{session_id}/parts/{file_id}_{filename}",
      "drawing_file_keys": ["intake/{session_id}/drawings/{file_id}_{filename}"],
      "material": "...",
      "tolerance": "custom",
      "tolerance_detail": "±0.001 on all diameters",
      "quantity": 10,
      "target_unit_price": 12.50,
      "notes": "..."
    }
  ],
  "global": {
    "required_delivery_date": "2026-08-01",
    "lead_time_preference": "standard",
    "shipping_destination": {
      "postal_code": "90210"
    },
    "po_number": null,
    "nda_required": false,
    "notes": "..."
  }
}
```

2. React sends this manifest to `POST /sessions/{session_id}/submit`.
3. If the request succeeds and returns a `receipt_number`, React shows the [success screen](#326-success-screen). **This is the only condition under which a success screen is shown.**
4. If the request fails (network error, non-200 response), React shows an error state with:
   - An explanation that the submission failed but their work is saved.
   - A **Retry Submission** button. The retry re-sends the same manifest; uploaded files are already in S3 and do not need to be re-uploaded.
   - A suggestion to try again in a few minutes if retries continue to fail.
5. React must **never clear uploaded file state or form data on a submit failure.**

#### 3.2.4 Autosave Behavior

- Autosave fires on:
  - 30 seconds of user inactivity (debounced from last keypress/interaction).
  - Tab/step navigation (when the user moves between steps).
- Autosave calls `PUT /sessions/{session_id}/draft` with the current metadata state (excluding file blobs). Writes go to the WP DB and `meta/draft.json` in S3 for operations recovery only — not for customer-facing resume.
- Autosave is fire-and-forget from the UX perspective. Failures are silently retried once; if the second attempt fails, a subtle "Draft not saved" indicator appears but does not block the user.

#### 3.2.5 Page Refresh

- A full page reload **invalidates the current intake session**. On load, the form creates a new session (`POST /sessions`) and the customer starts from Step 1 with empty state.
- Do not persist form state, `session_id`, or JWT in `localStorage` or `sessionStorage`.
- Uploaded files under the previous session's S3 prefix are not recoverable in the new session. Orphan prefixes are cleaned up per lifecycle rules (see 3.3.3).
- If the customer completed Step 1 before refreshing, their contact info remains captured as a **warm lead** on the abandoned session row in WP DB.

#### 3.2.6 Success Screen

Shown only after submit returns a `receipt_number`. Replace the form with a simple confirmation view — no modal.

**Layout (top to bottom):**

1. **Success icon** — custom inline SVG, centered, visually prominent (e.g. checkmark, document, or brand-appropriate illustration). Bundled with the plugin.
2. **Primary heading** — e.g. *Thank you for submitting your quote request.*
3. **Supporting copy** — e.g. *Our team is reviewing your files. We'll send your quote to the email address you provided.* Keep tone professional and plain; do not promise a specific turnaround time.
4. **Reference line (footer of the view)** — `Ref: {receipt_number}` in **low visual hierarchy** (e.g. Tailwind `text-sm text-gray-500 font-normal`). Copyable is a plus but not required.

**V1 exclusions:** No manifest summary, no PDF, no "confirmation email sent" claim (customer email on receipt is not implemented in V1). No call-to-action button.

**Note:** V1 does not send automated email to the customer; the supporting copy sets the business expectation that follow-up happens by email. Fulfillment may be manual until a notification feature ships.

---

### 3.3 S3 Storage Design

#### 3.3.0 Bucket Access

The intake bucket (or `intake/` prefix) is **private**. No public read or list bucket policies. Objects are accessible only via:

- Pre-signed PUT URLs issued to the browser (upload)
- Server-side credentials on WordPress (HeadObject, PutObject for meta files)
- Server-side credentials on the ERP (import read, prefix delete)

Unguessable keys alone are not sufficient — bucket policy must deny anonymous access. Direct object URLs without a valid signature return 403.

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
- Each URL authorizes a **PUT** to one specific key only. The plugin never issues pre-signed DELETE, GET, or LIST URLs to the browser.
- Content-type conditions are set per file type (part vs. drawing), enforced at the policy level, not just client validation.

#### 3.3.3 Lifecycle Rules

Apply the following S3 lifecycle rules to the `intake/` prefix:

| Rule | Condition | Action |
|------|-----------|--------|
| Expire unconfirmed sessions | Session prefix under `intake/` with no `receipt.json`, older than 30 days | Delete entire prefix |
| Post-import intake cleanup | Handled at ERP quote creation (see 3.5.2), not by bucket lifecycle | — |

Note: The 30-day rule requires a weekly cron job (ERP or ops) to LIST the `intake/` prefix and delete session prefixes older than 30 days with no `receipt.json`. S3 lifecycle rules alone cannot detect absent sibling objects.

---

### 3.4 Final Submit Server-Side Validation

When `POST /sessions/{session_id}/submit` is called, the WordPress plugin must execute the following sequence. Any failure at any step returns an error to the client and does not advance to the next step.

1. **Validate JWT.** Verify signature, expiry, and that `session_id` in the JWT matches the URL parameter.
2. **Validate session state.** Check the WP DB `rfq_sessions` row. If `status` is already `submitted`, return the existing `receipt_number` (idempotent re-submission is safe).
3. **Validate manifest fields.** Check all required fields are present and valid. Return field-level errors if not. `contact.email` must pass the same standard email validation as `PATCH /lead`. If `contact.phone` is set, it must match `^[0-9]{10}$` and `contact.phone_country_code` must be present (V1: `"1"`). If `contact.phone` is null, `phone_country_code` must be null. `parts` array length must be ≥ 1 and ≤ `RFQ_MAX_PARTS` (20 in V1). Each part `material` must be a non-empty string. Each entry in `parts` must include a unique `part_id`, a `part_file_key`, and valid metadata; `drawing_file_keys` must be an array (empty allowed). Each part `quantity` must be an integer ≥ 1. Each part `tolerance` must be `standard`, `precision`, or `custom`. If `tolerance` is `custom`, `tolerance_detail` is required (non-empty string). `target_unit_price`, if present, must be a number ≥ 0 with at most 2 decimal places; omit or `null` if not provided. `global.lead_time_preference` must be one of: `no_rush`, `standard`, `target_date`, `expedited`, `economy`. `global.nda_required` must be a boolean. `global.shipping_destination.postal_code` is required and must match US ZIP or Canadian postal code format. `global.required_delivery_date` is required, ISO date format, and must not be before today (UTC).
4. **Confirm S3 objects exist.** For every `part_file_key` and `drawing_file_key` declared in the manifest, call S3 `HeadObject`. If any declared key is missing or returns an error, abort and return an error identifying which files are missing. Objects in the session prefix that are **not** listed in the manifest are not validated and are not an error — they are ignored until ERP import cleanup.
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
9. **Notify ERP (best-effort).** If an ERP webhook URL is configured (see 3.1.6, 5.2), POST the import webhook. If the URL is blank, skip. Failure is logged; does not roll back the receipt or change the client response.
10. **Return receipt number** to the client.

**Failure handling for steps 5–8:** If step 7 or 8 fails after step 5 has succeeded, the system is in a partially-written state. The ERP import worker must treat any `manifest.json` without a corresponding `receipt.json` as incomplete and skip it. A WP admin cron job should alert on sessions where a `manifest.json` exists without a `receipt.json` after more than 15 minutes, so the operations team can investigate.

---

### 3.5 ERP Import

Import is **owned entirely by the ERP**. WordPress stops at `status = 'submitted'`. The ERP discovers work from S3 and does not read or write WordPress.

#### 3.5.1 Import Triggers

Two paths feed the same import logic:

| Trigger | When | Role |
|---------|------|------|
| **Webhook** | WP POST on new receipt (3.1.6) | Fast path when ERP is online |
| **S3 poll worker** | Scheduled, approximately every 5 minutes | Reliable fallback; catches missed webhooks and ERP downtime |

**S3 poll:** List or scan the `intake/` prefix for `meta/receipt.json` objects. For each receipt, read `receipt_number` and check ERP Postgres for an existing quote with `source_receipt_number`. Import those not yet processed.

The webhook and the poll worker must be **idempotent** — either may attempt the same receipt; only one quote is created.

#### 3.5.2 Import Sequence

For each unprocessed receipt:

1. Read `receipt.json` from S3 to get the `manifest_key`.
2. Read `manifest.json` from S3.
3. Create the official quote record in Supabase Postgres with a new quote number (`source_receipt_number` = receipt number from step 1).
4. Copy **only** the S3 objects referenced in the manifest (`part_file_key` and each entry in `drawing_file_keys` for every part) to the canonical quote storage location (e.g., `quotes/{quote_number}/`). Do not bulk-copy the entire intake prefix.
5. Write all part, metadata, and contact records to Postgres.
6. Delete the entire `intake/{session_id}/` prefix in S3 (manifest, receipt, draft meta, referenced files, and any unreferenced upload orphans). Run this only after steps 4–5 succeed. Failed imports must not delete the prefix.

#### 3.5.3 Import Idempotency

Before creating a quote record, check whether a quote with `source_receipt_number = receipt_number` already exists. If so, skip creation. If the intake prefix still exists, run step 6 (delete prefix) and return success.

---

### 3.6 Fallback Behavior Reference

| Failure Scenario | Expected Behavior |
|------------------|-------------------|
| WordPress down when user visits the RFQ page | Health check fails; Airtable iframe renders automatically |
| WordPress goes down mid-session | Files already uploaded to S3 are safe. In-memory form state is preserved while the tab stays open. User sees a submit error with retry guidance. Submit succeeds once WP recovers. |
| Customer refreshes the page mid-session | Current session is abandoned. New session starts from Step 1. Prior uploads are not carried over. Warm lead from Step 1 on the old session is retained in WP DB. |
| S3 unavailable for a file upload | Per-file upload error with inline retry button. User cannot proceed to submit until all required files are confirmed uploaded. |
| S3 unavailable at submit time | `HeadObject` check fails; WP returns an error; user sees "submission failed, your work is saved, try again." Files are already in S3 and will not need re-uploading. |
| ERP is down at submit time | No user impact. Receipt is written by WordPress. Webhook may fail silently. ERP S3 poll worker imports on the next cycle once ERP recovers. |
| ERP webhook fails | No user impact. Same as ERP down — S3 poll catches the receipt within ~5 minutes. |
| ERP import fails | Worker retries on next webhook or poll cycle. Receipt remains in S3 until import succeeds. Customer already has a receipt number. |
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

### 4.4 Upload-Only S3 Access (Customer)

- The WordPress plugin issues **pre-signed PUT URLs only**. It must never issue pre-signed DELETE, GET, or LIST URLs to the browser.
- Customers cannot remove objects from the intake bucket. Removing a part row or file in the UI updates form state only; orphan objects remain until ERP import deletes the session prefix.
- The manifest defines which objects belong to the quote; unreferenced objects in the prefix are not copied to canonical quote storage.

### 4.5 Content-Type Enforcement

- S3 policy conditions enforce content-type per file category. Client-side validation is a UX convenience only.

### 4.6 ERP Webhook Authentication

- WordPress signs each import webhook with HMAC-SHA256 (`X-RFQ-Signature` header) using the shared secret from plugin admin settings.
- The ERP webhook endpoint rejects requests with missing or invalid signatures. The same secret value must be configured on the ERP side for that environment (staging vs production).
- Secrets must never be exposed to the browser or committed to source control.
- The ERP does not receive WordPress database credentials and does not call WordPress REST endpoints for import.

### 4.7 Secrets in Plugin Settings

S3 secret access key, ERP webhook shared secret, and JWT signing secret are configured in WP admin (see 5.2). Managed hosting has no server env var access.

**Encrypt at rest — not encoding.** Values are encrypted before persistence in `wp_options` (e.g. AES-256-GCM via PHP `openssl` or libsodium `secretbox`). Base64 or obfuscation alone is insufficient. A plugin-specific encryption key is generated on activation and stored separately; decryption happens only in PHP during server operations (pre-signed URLs, JWT signing, webhook HMAC).

**Write-only admin UI:**

- Render secret fields as `type="password"` (or equivalent). Never pre-fill with the stored value.
- If a secret is already configured, show placeholder text such as *Configured — enter a new value to rotate* and an indicator that a value is set.
- On save: **blank field preserves the existing secret**; **non-blank field replaces** it (encrypt and store the new value).
- Operators **cannot view** a secret after it has been saved — only rotate it.

**Never expose secrets to the client:** REST responses, localized script config, HTML source, and logs must not contain plaintext or ciphertext of these values.

Applies to: S3 secret access key, ERP webhook shared secret, JWT signing secret.

### 4.8 Intake Bucket Privacy

- The S3 bucket (or at minimum the `intake/` prefix) must not allow public read or list access.
- Customer CAD files and manifests are accessible only through pre-signed URLs or server-side credentials (WordPress plugin, ERP import worker).

### 4.9 JWT Secret Rotation

- Rotating the JWT signing secret follows the write-only replace flow above. Rotation invalidates all in-flight session JWTs. Perform during low-traffic periods.

---

## 5. Operational Requirements

### 5.1 Monitoring and Alerting

| Alert | Condition | Severity |
|-------|-----------|----------|
| Health check degraded | `GET /rfq/v1/health` returns non-200 for more than 60 seconds | High |
| Orphaned manifests | `manifest.json` exists without `receipt.json` after 15 minutes | Medium |
| Import backlog | ERP: more than 20 `receipt.json` objects in S3 with no matching quote in Postgres, oldest older than 1 hour | Medium |
| Session creation spike | More than 50 sessions created in a 5-minute window | Low (possible abuse) |

### 5.2 Plugin Admin Settings

WordPress runs on managed hosting without direct access to server environment variables. Operational settings must be editable in the **WordPress admin plugin settings screen** after install — no redeploy required to point a WP testing branch at a staging ERP or to disable webhooks.

| Setting | Required | Notes |
|---------|----------|-------|
| **S3 storage** | | Supabase S3-compatible storage for intake uploads and receipts |
| S3 endpoint URL | Yes | Supabase storage API endpoint |
| Bucket name | Yes | |
| Access key ID | Yes | |
| Secret access key | Yes | Write-only password field; encrypted at rest; rotate by entering new value |
| Region | If needed | Required by the S3 client library if not inferred from endpoint |
| JWT signing secret | Yes | Write-only; encrypted at rest; rotation invalidates in-flight sessions |
| Airtable fallback embed URL | Yes (for fallback) | Used when health check fails. Not hardcoded. |
| International RFQ email | Yes | Shown in form helper text for customers outside US/Canada (e.g. "Email your RFQ to …"). Does not send mail — display only. |
| Sales contact email | Yes | Shown when "Treat as NDA" is checked — for customers who need a formal signed NDA before sharing IP. Does not send mail — display only. |
| Material catalog overrides | No | Add, disable, or rename entries from the shipped default JSON. Blank = defaults only. |
| ERP import webhook URL | No | Full URL to the ERP receipt webhook. **May be left blank** — webhook is skipped; ERP S3 poll handles import. |
| ERP webhook shared secret | When URL is set | Write-only; encrypted at rest; must match target ERP environment. Leave blank if webhook URL is blank. |

Typical pairing: WP plugin testing branch → staging S3 bucket (or shared dev bucket) + blank webhook or staging ERP URL; WP production → production bucket + production ERP URL. Misconfigured webhook secret causes webhook failure only — S3 poll still imports within ~5 minutes.

### 5.3 Data Retention

- Warm leads (sessions with `status = 'draft'`): retain WP DB rows for 90 days, then archive or delete.
- Submitted receipts: retain WP DB rows indefinitely (they are lightweight index rows).
- S3 intake prefixes: deleted by the ERP import worker after successful quote creation (step 3.5.2). Unreceipted session prefixes (no `receipt.json`): deleted by the 30-day cleanup cron. Retain indefinitely only when import repeatedly fails — alert and investigate.

---

## 6. Out of Scope (V1)

- Customer-facing RFQ status tracking portal.
- Email notifications to customers on receipt (may be added as a plugin extension).
- Internal ERP notifications on new RFQ imports (ERP can handle this internally once the import record is written).
- Draft resumption after page refresh or on a different device.
- International shipping via the online form (outside US/Canada — use international RFQ email instead).
- Formal NDA generation, e-signature, or automated NDA delivery through the intake form.
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
- The customer-facing form is implemented in **TypeScript** (compiled to a JS bundle by Vite); there are no plain JavaScript source files in the form codebase.
- All form UI styling uses **Tailwind CSS** utility classes; the shipped bundle includes a single compiled CSS file with no separate hand-written component stylesheets.
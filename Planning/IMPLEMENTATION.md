# Implementation Plan: RFQ Intake (V1)

**Source of truth:** `Planning/PRD.md`, `Planning/ADD.md`, `CONTEXT.md`  
**Out of scope for this doc:** items in `Planning/FUTURE.md`  
**Repo status:** Greenfield — no plugin code exists yet.

---

## 1. Overview

Build a WordPress plugin (`rfq-intake`) with a React multi-step RFQ form embedded via `[rfq_form]`. WordPress owns intake through `submitted`; the ERP imports from private S3 asynchronously.

**Deliverables in this repo:**

| # | Deliverable | Owner |
|---|-------------|--------|
| A | WordPress plugin (PHP): REST API, admin settings, S3, receipts | This repo |
| B | TypeScript + React + Tailwind form bundle built into plugin `build/` | This repo |
| C | ERP import worker + webhook endpoint | ERP repo (spec in PRD §3.5) |

**Recommended build order:** A1 → A2 → A3 → B1 → B2 → A4 → B3 → C1. Do not start the React UI until `POST /sessions` and `GET /health` work.

---

## 2. Assumptions

| Assumption | Value |
|------------|--------|
| PHP | 8.3+ |
| WordPress | 6.4+ |
| Frontend toolchain | React 18, TypeScript, Vite, Tailwind CSS |
| Form source language | TypeScript only (`.ts`/`.tsx`) — no plain `.js`/`.jsx` source |
| Form styling | Tailwind CSS utility classes only — no CSS modules or per-component stylesheets |
| JWT library | `firebase/php-jwt` |
| S3 client | `aws/aws-sdk-php` (Supabase S3-compatible endpoint) |
| Plugin slug | `rfq-intake` |
| REST namespace | `rfq/v1` |
| ERP codebase | Separate Remix repo; implement §3.5 there using this manifest contract |
| Local dev | Docker WordPress or existing staging site; Supabase dev bucket |
| Tests | Production test stack per [Planning/TESTING.md](TESTING.md): PHPUnit 11, wp-env integration, Vitest, Playwright, progressive GitHub Actions gates, acceptance-criterion registry. Manual staging smoke supplements automation before prod deploy. |

If Supabase S3 requires path-style endpoints, set `'use_path_style_endpoint' => true` on the S3 client.

---

## 3. Step-by-step Implementation Plan

### Phase 0 — Repository scaffold

**Step 0.1** Create directory layout:

```
wp-rfq-plugin/
├── rfq-intake/                      # WP plugin (deployable folder)
│   ├── rfq-intake.php
│   ├── includes/
│   ├── admin/
│   ├── assets/materials/default.json
│   └── build/                       # Vite output (gitignored until CI builds)
├── frontend/                        # TypeScript + React source
│   ├── package.json
│   ├── vite.config.ts
│   ├── tailwind.config.ts
│   ├── postcss.config.js
│   └── src/
│       ├── index.css                # @tailwind directives only
│       └── ...
├── tests/                           # Planned — scaffold at M1; see TESTING.md §7
│   ├── php/unit/
│   ├── php/integration/
│   ├── contract/schemas/
│   └── e2e/
└── Planning/
```

**Test infrastructure:** Introduced at **M1** per [TESTING.md §4](TESTING.md). Phase 0 does not create `composer.json`, `.wp-env.json`, CI workflows, or test files.

**Step 0.2** Bootstrap `rfq-intake/rfq-intake.php`:

- Plugin header comment block
- Define constants: `RFQ_INTAKE_VERSION`, `RFQ_MAX_PARTS` (20), `RFQ_MAX_UPLOAD_URLS_PER_SESSION` (200), `RFQ_SESSION_RATE_LIMIT` (10/hour/IP)
- Require autoloaded includes
- Register activation hook → `RFQ_Activator::activate()`
- Register `RFQ_Plugin::init()` on `plugins_loaded`

**Step 0.3** Add `frontend/vite.config.ts` with:

- `base: './'`
- `build.outDir: '../rfq-intake/build'`
- `build.rollupOptions.input: src/main.tsx`
- Output single JS + CSS bundle (`rfq-form.js`, `rfq-form.css`)

**Step 0.4** Configure Tailwind CSS:

- Install `tailwindcss`, `postcss`, `autoprefixer`
- `tailwind.config.ts`: set `content: ['./src/**/*.{ts,tsx}']`; optional `prefix: 'rfq-'` or scope under `#rfq-form-root` if theme bleed is a problem in staging
- `postcss.config.js`: `tailwindcss`, `autoprefixer`
- `src/index.css`: `@tailwind base; @tailwind components; @tailwind utilities;` plus any minimal scoped reset on `#rfq-form-root`
- Import `index.css` from `main.tsx`

All step components use Tailwind utility classes for layout and styling — no separate `.css` files per component.

---

### Phase 1 — Database, secrets, admin settings

**Step 1.1** `includes/class-rfq-activator.php` — create tables on activation:

```sql
-- rfq_sessions (see PRD §3.1.3)
-- rfq_receipt_sequences (date CHAR(8) PK, seq INT UNSIGNED)
```

Use `dbDelta()`. Include nullable admin-summary columns from PRD §3.1.3 (`shipping_postal_code`, `submitted_part_count`). Set `s3_prefix = 'intake/{session_id}/'` on session create, not in schema default.

**Step 1.2** `includes/class-rfq-secrets.php`:

- On activation: generate `rfq_encryption_key` (32 random bytes, base64) if missing
- `encrypt( string $plaintext ): string` — AES-256-GCM, store `nonce + tag + ciphertext` base64
- `decrypt( string $blob ): string`
- `get_secret( string $option_name ): ?string` / `set_secret( string $option_name, string $plaintext )`

Encrypt: `rfq_s3_secret_key`, `rfq_jwt_secret`, `rfq_webhook_secret`.

**Step 1.3** `admin/class-rfq-admin-settings.php` + `admin/views/settings-page.php`:

Register settings group `rfq_intake_settings` with fields from PRD §5.2. Split the settings screen into **General** and **Form defaults** tabs (`?tab=general` default, `?tab=defaults` for catalog):

| Option key | Type | Notes |
|------------|------|-------|
| `rfq_s3_endpoint` | text | General tab |
| `rfq_s3_bucket` | text | General tab |
| `rfq_s3_access_key_id` | text | General tab |
| `rfq_s3_secret_key` | secret | write-only; General tab |
| `rfq_s3_region` | text | optional; General tab |
| `rfq_jwt_secret` | secret | write-only; auto-generate on first activation if empty; General tab |
| `rfq_airtable_embed_url` | url | General tab |
| `rfq_international_rfq_email` | email | General tab |
| `rfq_sales_contact_email` | email | General tab |
| `rfq_erp_webhook_url` | url | optional; General tab |
| `rfq_erp_webhook_secret` | secret | write-only; General tab |
| `rfq_material_overrides` | json | Form defaults tab — visual catalog editor + synced overrides JSON |

Form defaults tab (`admin/views/settings-defaults-tab.php` + `admin/assets/catalog-editor.js`): table editor for shipped/custom materials (enable, rename label, add custom entries), customer-facing preview, and advanced overrides JSON kept in sync client-side. Shipped defaults: rename/disable only; custom entries: full alias/dropdown control.

**Step 1.4** `includes/class-rfq-material-catalog.php`:

- Load `assets/materials/default.json`
- Merge admin overrides (disable by id, rename label, append new entries)
- Expose `get_effective_catalog(): array` for REST bootstrap config
- Expose `get_editor_rows()`, `build_overrides_from_rows()`, and `encode_overrides()` for the admin catalog editor

Ship `default.json` with at least: 1018 Steel, 6061 Aluminum, 7075 Aluminum, 304 Stainless — include `aliases` and `show_in_dropdown` flags per PRD §3.1.7.

**Step 1.5** `admin/class-rfq-admin-intake-list.php` + `admin/views/intake-list-page.php`:

- Add a WordPress admin submenu for the RFQ intake ledger.
- Use the same standard administrator permission model as the plugin settings page; do not introduce custom capabilities/RBAC in V1.
- Query `rfq_sessions` rows ordered by `created_at DESC`; do not use `updated_at` for default ordering.
- Include every `rfq_sessions` row; do not hide incomplete, empty, or suspected bot/session-spam rows in V1.
- Add pagination with a default page size of 25 rows and selectable page sizes of 50 or 75 rows.
- Do not add status/search filtering in V1.
- Render a read-only table with name (`first_name` + `last_name` as one column), company name, email, phone number, shipping postal code / ZIP code, created date, raw DB status, and submitted part count for completed RFQs.
- Format stored V1 phone values for display as `+1 (555) 555-0100`; keep DB/API storage normalized as `phone = 10 digits` and `phone_country_code = "1"`.
- Display name/company/email values from the DB without admin-list-only casing transforms.
- Display email exactly as stored in the database; do not apply admin-list-only casing or normalization.
- Display created date and time in the configured WordPress site timezone.
- Render missing values as empty cells, not placeholder text.
- Render submitted part count as an empty cell unless `status = submitted`.
- Display `rfq_sessions.status` directly (`draft`, `submitted`, or future `abandoned`); do not derive separate admin labels for completed-contact attempts or session starts in V1.
- Do not show ERP import status.
- Do not add CSV export in V1.

---

### Phase 2 — REST API foundation

**Step 2.1** `includes/class-rfq-rest-controller.php` — register routes on `rest_api_init`:

| Method | Route | Callback |
|--------|-------|----------|
| GET | `/rfq/v1/health` | `health_check` |
| POST | `/rfq/v1/sessions` | `create_session` |
| POST | `/rfq/v1/sessions/(?P<session_id>[a-f0-9-]+)/refresh` | `refresh_session` |
| PATCH | `/rfq/v1/sessions/(?P<session_id>...)/contact` | `patch_contact` |
| POST | `/rfq/v1/sessions/(?P<session_id>...)/upload-urls` | `upload_urls` |
| PUT | `/rfq/v1/sessions/(?P<session_id>...)/draft` | `put_draft` |
| POST | `/rfq/v1/sessions/(?P<session_id>...)/submit` | `submit` |

**Step 2.2** `includes/class-rfq-jwt.php`:

```php
public function issue( string $session_id ): string { /* HS256, exp +3600, sub rfq-session */ }
public function validate( string $token, string $expected_session_id ): object { /* throws */ }
```

Permission callback for JWT routes: read `Authorization: Bearer`, validate, attach session to request.

**Step 2.3** `includes/class-rfq-rate-limiter.php`:

- Session creation: transient key `rfq_sessions_{ip_hash}`, max 10/hour → HTTP 429
- Upload URLs: count rows in a session-scoped transient or DB counter, max 200/session

**Step 2.4** Implement endpoints incrementally:

1. **`POST /sessions`** — insert `rfq_sessions` row (`status=draft`), return `{ session_id, token }`
2. **`POST /sessions/{id}/refresh`** — reject if `status=submitted`; return `{ token }`
3. **`GET /health`** — verify S3 settings present; `HeadBucket` or lightweight S3 call; `{ status: "ok" }` or 503

Do not implement automatic `abandoned` transitions in V1. The schema reserves the status for a future explicit abandonment action; tab close, refresh, and inactivity remain `draft` until retention cleanup.

---

### Phase 3 — S3 integration

**Step 3.1** `includes/class-rfq-s3-client.php`:

- Build AWS S3 client from decrypted admin credentials + custom endpoint
- `head_object( string $key ): bool`
- `put_json( string $key, array $data ): void`
- `create_presigned_put( string $key, string $content_type, int $max_bytes, int $expires_seconds = 1800 ): string`

Pre-signed PUT behavior must follow PRD §3.3.2:

- Bind the URL to the **exact server-generated key**.
- Sign the expected `Content-Type` header where the provider supports header enforcement.
- Do not assume POST-policy features such as `content-length-range` are available for PUT URLs.
- Store or retrieve enough object metadata during submit validation to enforce size and declared file category with `HeadObject` or equivalent.
- Confirm Supabase-compatible behavior during the M2 S3 validation spike before treating provider-specific enforcement as guaranteed.

- Part: max 524288000 bytes, content-type `application/octet-stream`
- Drawing: max 52428800 bytes, content-type one of pdf/png/jpeg

**Step 3.2** `POST /upload-urls` body:

```json
{
  "part_id": "uuid",
  "file_type": "part|drawing",
  "filename": "bracket.step",
  "content_type": "application/octet-stream"
}
```

Server generates `file_id` (UUID v4), sanitizes filename, returns:

```json
{
  "upload_url": "https://...",
  "file_key": "intake/{session_id}/parts/{file_id}_{sanitized}"
}
```

Increment per-session URL counter; reject at 200.

**Step 3.3** `PATCH /contact` — trim leading/trailing whitespace from contact fields, convert blank optional `company` and `job_title` values to `null`, convert blank phone to `phone = null` and `phone_country_code = null`, validate per PRD §3.1.5, and update `rfq_sessions` contact columns. Do not title-case names or lowercase email before storage.

**Step 3.4** `PUT /draft` — accept metadata JSON (no file blobs); write to WP option column or `draft_json` TEXT column on session row **and** S3 `meta/draft.json`. If `global.shipping_destination.postal_code` is present and valid, also update `rfq_sessions.shipping_postal_code` for the admin intake list. Do not block warm-lead capture on ZIP/postal code; it is required only for final RFQ submit.

> **Schema note:** Add `draft_json LONGTEXT NULL` to `rfq_sessions` in activator if storing draft in DB; PRD implies draft persistence — pick DB column or separate table.

---

### Phase 4 — Submit pipeline

**Step 4.1** `includes/class-rfq-manifest-validator.php`:

Single method `validate( array $manifest ): void|WP_Error` implementing every rule in PRD §3.4 step 3. Return field-keyed errors:

```php
return new WP_Error('rfq_validation', 'Invalid manifest', [
  'status' => 422,
  'fields' => [ 'global.shipping_destination.postal_code' => 'Invalid US/CA postal code' ],
]);
```

Include validators:

- Email (`filter_var`)
- Phone `/^[0-9]{10}$/` + country code rules
- Postal code US 5/9 digit or CA `A1A 1A1`
- Delivery date ≥ today UTC
- Lead time enum
- Parts count, material, tolerance, quantity, target_unit_price

**Step 4.2** `includes/class-rfq-receipt-service.php`:

Implement submit sequence PRD §3.4 steps 1–10:

1. JWT + session checks (idempotent if already submitted)
2. `Manifest_Validator::validate()`
3. HeadObject for all manifest file keys
4. Put manifest.json
5. Allocate receipt number:

```php
// rfq_receipt_sequences: INSERT ... ON DUPLICATE KEY UPDATE seq = seq + 1
// receipt_number = sprintf('RFQ-%s-%06d', gmdate('Ymd'), $seq);
```

6. Put receipt.json
7. Update session row `status=submitted`, `receipt_number`, `submitted_at`, `shipping_postal_code`, and `submitted_part_count = count($manifest['parts'])`
8. Fire webhook async (see Step 4.3)
9. Return `{ receipt_number }`

On failure after manifest.json written: log alert condition (manifest without receipt >15 min — WP cron in Phase 5).

**Step 4.3** `includes/class-rfq-webhook.php`:

```php
public function notify_receipt( string $receipt_number, string $session_id ): void {
  $url = get_option('rfq_erp_webhook_url');
  if ( empty($url) ) return;
  $body = wp_json_encode([...]);
  $sig = hash_hmac('sha256', $body, Secrets::get_secret('rfq_webhook_secret'));
  wp_remote_post($url, [
    'timeout' => 5,
    'blocking' => false,
    'headers' => [ 'Content-Type' => 'application/json', 'X-RFQ-Signature' => $sig ],
    'body' => $body,
  ]);
}
```

Never block submit response on webhook result.

---

### Phase 5 — WordPress integration (shortcode + assets)

**Step 5.1** `includes/class-rfq-shortcode.php`:

- Register `[rfq_form]`
- Output `<div id="rfq-form-root"></div>`
- Enqueue `build/rfq-form.js`, `build/rfq-form.css` only when shortcode present
- `wp_localize_script('rfq-form', 'rfqFormConfig', [...])` with:

```php
[
  'restBase' => rest_url('rfq/v1'),
  'nonce' => wp_create_nonce('wp_rest'), // if needed for cookie auth (not for public form)
  'airtableEmbedUrl' => get_option('rfq_airtable_embed_url'),
  'internationalRfqEmail' => get_option('rfq_international_rfq_email'),
  'salesContactEmail' => get_option('rfq_sales_contact_email'),
  'maxParts' => RFQ_MAX_PARTS,
  'materials' => Material_Catalog::get_effective_catalog(),
]
```

Do **not** pass secrets or JWT in localized config.

**Step 5.2** Optional WP-Cron: daily check for orphaned manifest.json without receipt.json >15 minutes → `error_log` or admin notice hook.

---

### Phase 6 — React form (TypeScript + Tailwind)

**Step 6.1** `frontend/src/api/client.ts`:

- `apiFetch(path, { method, token, body })` wrapping `fetch(restBase + path)`
- Attach `Authorization: Bearer` when token set
- Parse WP REST error shape

**Step 6.2** `frontend/src/state/FormContext.tsx`:

- State: `token`, `sessionId`, `step`, `contact`, `parts[]`, `global`, `submitError`
- JWT refresh timer: decode exp, refresh at T-10 min via `POST /sessions/{id}/refresh`
- On refresh failure: set `tokenWarning=true`, pause autosave

**Step 6.3** Startup (`App.tsx`):

1. Health check (3s timeout)
2. If fail → render Airtable iframe from config
3. If ok → `POST /sessions`, store token, render stepper

**Step 6.4** Step components:

| Component | File | Key behavior |
|-----------|------|--------------|
| StepContact | `steps/StepContact.tsx` | Masked phone; PATCH contact on blur/advance |
| StepUploads | `steps/StepUploads.tsx` | Part rows, UUID part_id, direct S3 PUT with progress |
| StepPartMeta | `steps/StepPartMeta.tsx` | Material dropdown + typeahead; tolerance |
| StepGlobal | `steps/StepGlobal.tsx` | Delivery date, lead time, postal code, NDA checkbox + notice |
| StepReview | `steps/StepReview.tsx` | Summary + submit |
| SuccessView | `SuccessView.tsx` | SVG + copy + `Ref: {receipt}`; muted ref line via Tailwind (e.g. `text-sm text-gray-500`) |

Style every step component with Tailwind classes only. Do not add CSS modules, styled-components, or hand-written stylesheets.

**Step 6.5** Upload helper `frontend/src/lib/uploadFile.ts`:

```typescript
export async function uploadFile(
  uploadUrl: string,
  file: File,
  contentType: string,
  onProgress: (pct: number) => void,
): Promise<void> {
  // XMLHttpRequest or fetch with ReadableStream for progress
  const res = await fetch(uploadUrl, { method: 'PUT', body: file, headers: { 'Content-Type': contentType } });
  if (!res.ok) throw new Error(`S3 upload failed: ${res.status}`);
}
```

**Step 6.6** Autosave hook `useAutosave.ts`:

- Debounce 30s inactivity + fire on step change
- `PUT /draft` with `{ contact, parts: metadata only, global }` — strip upload URLs, keep file keys
- Retry once; show subtle "Draft not saved"

**Step 6.7** Submit flow:

- Build manifest from state (PRD §3.2.3 shape)
- `POST /submit` with manifest body
- On success → SuccessView
- On failure → keep all state, show retry button

**Step 6.8** Build pipeline:

```bash
cd frontend && npm ci && npm run build
```

Document in README. CI should run build before plugin zip artifact.

---

### Phase 7 — ERP import (ERP repository)

Implement in Remix ERP — not this plugin repo. Contract:

**Webhook endpoint** `POST /api/intake/receipt` (path is ERP's choice; must match WP admin URL):

- Verify `X-RFQ-Signature` HMAC-SHA256
- Enqueue or run import job with `{ receipt_number, session_id, receipt_key }`

**Poll worker** (~5 min cron):

- List S3 `intake/` prefixes with `meta/receipt.json`
- Skip if `quotes.source_receipt_number` exists
- Run import sequence PRD §3.5.2

**Import service** (pseudocode):

```typescript
async function importReceipt(receiptKey: string) {
  const receipt = await s3.getJson(receiptKey);
  const manifest = await s3.getJson(receipt.manifest_key);
  if (await db.quoteExists(receipt.receipt_number)) {
    await s3.deletePrefix(`intake/${receipt.session_id}/`);
    return;
  }
  const quote = await db.createQuote({ sourceReceiptNumber: receipt.receipt_number, ...manifest });
  for (const part of manifest.parts) {
    await s3.copy(manifest.part_file_key, `quotes/${quote.number}/...`);
    // drawings too
  }
  await db.insertPartsAndContact(quote.id, manifest);
  await s3.deletePrefix(`intake/${receipt.session_id}/`);
}
```

---

## 4. Code Snippets (reference)

### Manifest TypeScript type (`frontend/src/types/manifest.ts`)

```typescript
export interface RfqManifest {
  session_id: string;
  contact: {
    first_name: string;
    last_name: string;
    email: string;
    company: string | null;
    phone: string | null;
    phone_country_code: string | null;
    job_title: null;
  };
  parts: Array<{
    part_id: string;
    part_file_key: string;
    drawing_file_keys: string[];
    material: string;
    tolerance: 'standard' | 'precision' | 'custom';
    tolerance_detail: string | null;
    threads_features?: string | null;
    quantity: number;
    target_unit_price: number | null;
    notes?: string | null;
  }>;
  global: {
    required_delivery_date: string; // YYYY-MM-DD
    lead_time_preference: 'no_rush' | 'standard' | 'target_date' | 'expedited' | 'economy';
    shipping_destination: { postal_code: string };
    po_number: string | null;
    nda_required: boolean;
    notes: string | null;
  };
}
```

### Postal code validation (PHP)

```php
public static function is_valid_postal_code( string $code ): bool {
  $code = strtoupper( str_replace( ' ', '', trim( $code ) ) );
  if ( preg_match( '/^\d{5}(\d{4})?$/', $code ) ) return true; // US
  if ( preg_match( '/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $code ) ) return true; // CA
  return false;
}
```

### JWT refresh response

```json
HTTP 200
{ "token": "eyJ..." }
```

```json
HTTP 401
{ "code": "rfq_token_expired", "message": "Session token expired" }
```

---

## 5. File-by-file Changes

| File | Action |
|------|--------|
| `rfq-intake/rfq-intake.php` | Create — plugin bootstrap |
| `rfq-intake/includes/class-rfq-activator.php` | Create — DB schema |
| `rfq-intake/includes/class-rfq-plugin.php` | Create — hooks, load classes |
| `rfq-intake/includes/class-rfq-secrets.php` | Create — encrypt/decrypt |
| `rfq-intake/includes/class-rfq-jwt.php` | Create — issue/validate |
| `rfq-intake/includes/class-rfq-s3-client.php` | Create — S3 ops + presign |
| `rfq-intake/includes/class-rfq-rest-controller.php` | Create — all REST routes |
| `rfq-intake/includes/class-rfq-manifest-validator.php` | Create — submit validation |
| `rfq-intake/includes/class-rfq-receipt-service.php` | Create — submit orchestration |
| `rfq-intake/includes/class-rfq-webhook.php` | Create — ERP notify |
| `rfq-intake/includes/class-rfq-rate-limiter.php` | Create |
| `rfq-intake/includes/class-rfq-material-catalog.php` | Create |
| `rfq-intake/includes/class-rfq-shortcode.php` | Create |
| `rfq-intake/admin/class-rfq-admin-settings.php` | Create |
| `rfq-intake/admin/class-rfq-admin-intake-list.php` | Create — read-only intake ledger |
| `rfq-intake/admin/views/settings-page.php` | Create — tabbed General + Form defaults |
| `rfq-intake/admin/views/settings-defaults-tab.php` | Create — material catalog editor |
| `rfq-intake/admin/assets/catalog-editor.js` | Create — UI ↔ JSON sync |
| `rfq-intake/admin/assets/catalog-editor.css` | Create — catalog editor styles |
| `rfq-intake/admin/views/intake-list-page.php` | Create — rows from `rfq_sessions` with raw DB status |
| `rfq-intake/assets/materials/default.json` | Create |
| `frontend/package.json` | Create — React, TypeScript, Vite, Tailwind |
| `frontend/vite.config.ts` | Create |
| `frontend/tailwind.config.ts` | Create |
| `frontend/postcss.config.js` | Create |
| `frontend/src/index.css` | Create — Tailwind entry |
| `frontend/tsconfig.json` | Create — strict TypeScript |
| `frontend/src/main.tsx` | Create — mount React, import `index.css` |
| `frontend/src/App.tsx` | Create |
| `frontend/src/api/client.ts` | Create |
| `frontend/src/state/FormContext.tsx` | Create |
| `frontend/src/steps/*.tsx` | Create — 5 steps + success |
| `frontend/src/lib/uploadFile.ts` | Create |
| `frontend/src/hooks/useAutosave.ts` | Create |
| `tests/php/ManifestValidatorTest.php` | Create at M3 — alongside ManifestValidator |
| `tests/php/PostalCodeTest.php` | Create at M3 |
| `composer.json` | Create at M1 — PHP deps + autoload PSR-4 `RFQ\\` |
| `.wp-env.json`, `phpunit.xml.dist`, `playwright.config.ts`, `.github/workflows/test.yml`, `scripts/check-acceptance-coverage.php`, `tests/**` (beyond rows above) | Planned M1–M5 — see [TESTING.md §7](TESTING.md) |
| `Planning/TESTING.md` | Create — testing spec (doc pass) |
| `Planning/IMPLEMENTATION.md` | This file |
| `README.md` | Update — dev setup commands |

---

## 6. Implementation Notes / Gotchas

1. **Submit idempotency:** If client retries submit after network timeout, return existing `receipt_number` when `status=submitted` — do not write duplicate manifest/receipt.

2. **Partial write alert:** If `manifest.json` exists but `receipt.json` does not after 15 minutes, ops must investigate. Do not let ERP import manifest-only packages (ADD §10).

3. **Page refresh:** Never persist JWT or form state in browser storage. New session on every full reload.

4. **Orphan S3 objects:** Expected when users remove part rows. Manifest is authoritative; ERP deletes whole prefix after import.

5. **Pre-signed URLs:** Bind to exact `file_key`, not prefix-only policies — prevents uploading to sibling keys within session.

6. **Webhook is optional:** Blank ERP URL in admin = poll-only path. Submit must succeed either way.

7. **Health check drives fallback:** Broken S3 config shows Airtable form — test this in staging before go-live.

8. **JWT rotation:** Rotating JWT secret in admin kills in-flight sessions — document for ops.

9. **Managed WP:** No `.env` — all config via admin settings UI.

10. **ERP is separate deploy:** Ship plugin before ERP import worker; receipts accumulate in S3 safely until ERP goes live.

11. **Content-Type on S3 PUT:** Browser must send the same `Content-Type` used when generating the presigned URL. Provider enforcement is validated in the M2 S3 spike; submit validation still checks available object metadata.

12. **UTC dates:** Server-side delivery date validation uses UTC date, not WP timezone.

13. **Tailwind vs WordPress theme:** Theme CSS may affect the mount node. Scope Tailwind under `#rfq-form-root` or use a prefix in `tailwind.config.ts` if staging shows bleed; do not add non-Tailwind stylesheets as a workaround.

---

## 7. Acceptance Criteria

Each plugin checkbox maps to a stable ID in [Planning/TESTING.md](TESTING.md) §3. Before V1 release, every `AC-WP-*` ID must have ≥1 automated test. ERP checkboxes map to `AC-ERP-*` (verified in ERP repo).

### WordPress plugin

- [ ] **AC-WP-009** — `[rfq_form]` renders React form on a WP page; scripts not loaded on other pages
- [ ] **AC-WP-008** — Form UI is styled with Tailwind only (no stray CSS files in `frontend/src/`)
- [ ] **AC-WP-007** — Frontend builds from TypeScript source with `npm run build` (no plain `.js`/`.jsx` in `frontend/src/`)
- [ ] **AC-WP-010** — Admin can configure S3, emails, Airtable URL, optional ERP webhook without redeploy
- [ ] **AC-WP-011** — Secrets are write-only in admin and encrypted in DB
- [ ] **AC-WP-028** — Admin can view a read-only intake list showing every session row with combined name, company, email, phone, ZIP/postal code, created date, raw DB status, and submitted part count for completed RFQs
- [ ] **AC-WP-012** — `GET /health` returns 200 only when S3 credentials work
- [ ] **AC-WP-013** — Full happy path: contact → upload part file → metadata → global → submit → receipt number
- [ ] **AC-WP-014** — Success screen shows SVG, thank-you copy, muted `Ref: RFQ-...`
- [ ] **AC-WP-001** — Submit retry after simulated network failure returns same receipt number (idempotent)
- [ ] **AC-WP-015** — Page refresh starts new session; prior Step 1 contact row remains in DB
- [ ] **AC-WP-002** — Health failure within 3s shows Airtable embed
- [ ] **AC-WP-016** — >20 parts blocked in UI and rejected on submit
- [ ] **AC-WP-006** — Rate limit: 11th session from same IP in 1 hour → 429
- [ ] **AC-WP-017** — Invalid manifest returns 422 with field errors
- [ ] **AC-WP-018** — Missing S3 file at submit returns error identifying missing keys
- [ ] **AC-WP-019** — Webhook POST fires on submit when URL configured; submit still succeeds when webhook target is down

### ERP (separate repo)

- [ ] **AC-ERP-002** — Webhook verifies HMAC signature
- [ ] **AC-ERP-003** — Poll worker imports receipt within 5 minutes when webhook skipped
- [ ] **AC-ERP-004** — Duplicate import attempts create one quote only
- [ ] **AC-ERP-005** — Import copies only manifest-referenced keys to quote storage
- [ ] **AC-ERP-006** — Import deletes `intake/{session_id}/` prefix after success
- [ ] **AC-ERP-007** — Import skips sessions with manifest but no receipt

---

## 8. Testing Plan

V1 uses a layered automated test pyramid with **progressive CI gates** tied to milestones M1–M5. Gates accumulate as features ship; the full PR-equivalent suite runs at pre-release.

**Canonical spec:** [Planning/TESTING.md](TESTING.md) — acceptance-criterion registry (§3), progressive CI (§4), S3/Supabase validation (§5), tooling (§8), manual staging (§11).

### PHPUnit (once M1 scaffold exists)

| Test class | Cases | AC IDs |
|------------|-------|--------|
| `ManifestValidatorTest` | valid manifest; missing email; custom tolerance without detail; qty 0; 21 parts; past delivery date; invalid postal code | AC-WP-016, AC-WP-017 |
| `PostalCodeTest` | US 5/9 digit; CA format; rejects garbage | AC-WP-017 |
| `PhoneValidatorTest` | 10 digits pass; 9 fail; phone requires country code 1 | AC-WP-017 |
| `ReceiptNumberTest` | format `RFQ-YYYYMMDD-000001`; daily rollover | AC-WP-022 |
| `JwtServiceTest` | sign/verify; expiry; session isolation | AC-WP-006 |
| `S3KeyBuilderTest` | sanitization; prefix; rejects client keys | AC-WP-024 |
| `RateLimiterTest` | session/IP limits; upload-url per session | AC-WP-006 |
| `SecretsTest` | encrypt; blank save preserves; no REST leak | AC-WP-020 |
| `ReceiptConcurrencyTest` | parallel submits → unique numbers | AC-WP-022 |
| `ActivatorTest` | double activation idempotent | AC-WP-023 |

Run (after M1): `composer test`, `composer test:integration`, `npm run test:acceptance-coverage`.

Manual staging checklists and pre-production smoke tests: [TESTING.md §11](TESTING.md).

---

## Suggested milestones / PR slicing

| Milestone | Scope | Demo | CI gates (cumulative) | Key AC IDs |
|-----------|--------|------|----------------------|------------|
| M1 | Phase 0–2 + health + sessions | REST client can create session | lint, php-unit, php-integration, contract, acceptance-coverage | AC-WP-006, 012, 023 |
| M2 | Phase 3 + lead + upload-urls | curl uploads file to S3 via presigned URL | M1 + upload-url integration, S3 unit/mock, S3 spike | AC-WP-010, 011, 020, 021, 024 |
| M3 | Phase 4 submit | curl submit returns receipt; S3 has receipt.json | M2 + submit integration, durability, idempotency | AC-WP-001, 005, 016–019, 022, 025, 026 |
| M4 | Phase 5–6 React Steps 1–2 | Upload UI works E2E | M3 + frontend-unit (steps 1–2) | AC-WP-007, 008, 003 (partial) |
| M5 | Phase 6 Steps 3–5 + success | Full form E2E | M4 + e2e-mocked, a11y smoke | AC-WP-002–004, 009, 013–015, 027 |
| M6 | Phase 7 ERP import | Quote in ERP from receipt | ERP repo | AC-ERP-* |
| Pre-release | — | Staging sign-off | Full suite + Supabase/LocalStack E2E | All AC-WP-* |

Ship M3 to staging before investing in React polish — proves durability story early. See [TESTING.md §4](TESTING.md) for gate details.

**Pre-release follow-up** (ops, CI, polish after Phase 6 UI): [PRE-RELEASE-BACKLOG.md](PRE-RELEASE-BACKLOG.md).

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
| B | React form bundle built into plugin `build/` | This repo |
| C | ERP import worker + webhook endpoint | ERP repo (spec in PRD §3.5) |

**Recommended build order:** A1 → A2 → A3 → B1 → B2 → A4 → B3 → C1. Do not start the React UI until `POST /sessions` and `GET /health` work.

---

## 2. Assumptions

| Assumption | Value |
|------------|--------|
| PHP | 8.1+ |
| WordPress | 6.4+ |
| Frontend toolchain | React 18, TypeScript, Vite |
| JWT library | `firebase/php-jwt` |
| S3 client | `aws/aws-sdk-php` (Supabase S3-compatible endpoint) |
| Plugin slug | `rfq-intake` |
| REST namespace | `rfq/v1` |
| ERP codebase | Separate Remix repo; implement §3.5 there using this manifest contract |
| Local dev | Docker WordPress or existing staging site; Supabase dev bucket |
| Tests | PHPUnit for PHP validators/services; manual E2E for uploads (no Playwright required in V1 unless team adds it) |

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
├── frontend/                        # React source
│   ├── package.json
│   ├── vite.config.ts
│   └── src/
├── tests/php/                       # PHPUnit
└── Planning/
```

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
- Output single JS + CSS bundle

---

### Phase 1 — Database, secrets, admin settings

**Step 1.1** `includes/class-rfq-activator.php` — create tables on activation:

```sql
-- rfq_sessions (see PRD §3.1.3)
-- rfq_receipt_sequences (date CHAR(8) PK, seq INT UNSIGNED)
```

Use `dbDelta()`. Set `s3_prefix = 'intake/{session_id}/'` on session create, not in schema default.

**Step 1.2** `includes/class-rfq-secrets.php`:

- On activation: generate `rfq_encryption_key` (32 random bytes, base64) if missing
- `encrypt( string $plaintext ): string` — AES-256-GCM, store `nonce + tag + ciphertext` base64
- `decrypt( string $blob ): string`
- `get_secret( string $option_name ): ?string` / `set_secret( string $option_name, string $plaintext )`

Encrypt: `rfq_s3_secret_key`, `rfq_jwt_secret`, `rfq_webhook_secret`.

**Step 1.3** `admin/class-rfq-admin-settings.php` + `admin/views/settings-page.php`:

Register settings group `rfq_intake_settings` with fields from PRD §5.2:

| Option key | Type | Notes |
|------------|------|-------|
| `rfq_s3_endpoint` | text | |
| `rfq_s3_bucket` | text | |
| `rfq_s3_access_key_id` | text | |
| `rfq_s3_secret_key` | secret | write-only |
| `rfq_s3_region` | text | optional |
| `rfq_jwt_secret` | secret | write-only; auto-generate on first activation if empty |
| `rfq_airtable_embed_url` | url | |
| `rfq_international_rfq_email` | email | |
| `rfq_sales_contact_email` | email | |
| `rfq_erp_webhook_url` | url | optional |
| `rfq_erp_webhook_secret` | secret | write-only |
| `rfq_material_overrides` | json | optional admin JSON for catalog overrides |

**Step 1.4** `includes/class-rfq-material-catalog.php`:

- Load `assets/materials/default.json`
- Merge admin overrides (disable by id, rename label, append new entries)
- Expose `get_effective_catalog(): array` for REST bootstrap config

Ship `default.json` with at least: 1018 Steel, 6061 Aluminum, 7075 Aluminum, 304 Stainless — include `aliases` and `show_in_dropdown` flags per PRD §3.1.7.

---

### Phase 2 — REST API foundation

**Step 2.1** `includes/class-rfq-rest-controller.php` — register routes on `rest_api_init`:

| Method | Route | Callback |
|--------|-------|----------|
| GET | `/rfq/v1/health` | `health_check` |
| POST | `/rfq/v1/sessions` | `create_session` |
| POST | `/rfq/v1/sessions/(?P<session_id>[a-f0-9-]+)/refresh` | `refresh_session` |
| PATCH | `/rfq/v1/sessions/(?P<session_id>...)/lead` | `patch_lead` |
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

---

### Phase 3 — S3 integration

**Step 3.1** `includes/class-rfq-s3-client.php`:

- Build AWS S3 client from decrypted admin credentials + custom endpoint
- `head_object( string $key ): bool`
- `put_json( string $key, array $data ): void`
- `create_presigned_put( string $key, string $content_type, int $max_bytes, int $expires_seconds = 1800 ): string`

Pre-signed policy must bind **exact key** (not just prefix), `Content-Type`, and `content-length-range` per PRD §3.3.2:

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

**Step 3.3** `PATCH /lead` — validate contact fields per PRD §3.1.5; update `rfq_sessions` lead columns.

**Step 3.4** `PUT /draft` — accept metadata JSON (no file blobs); write to WP option column or `draft_json` TEXT column on session row **and** S3 `meta/draft.json`.

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
7. Update session row `status=submitted`, `receipt_number`, `submitted_at`
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

### Phase 6 — React form

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
| StepContact | `steps/StepContact.tsx` | Masked phone; PATCH lead on blur/advance |
| StepUploads | `steps/StepUploads.tsx` | Part rows, UUID part_id, direct S3 PUT with progress |
| StepPartMeta | `steps/StepPartMeta.tsx` | Material dropdown + typeahead; tolerance |
| StepGlobal | `steps/StepGlobal.tsx` | Delivery date, lead time, postal code, NDA checkbox + notice |
| StepReview | `steps/StepReview.tsx` | Summary + submit |
| SuccessView | `SuccessView.tsx` | SVG + copy + `Ref: {receipt}` |

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
| `rfq-intake/admin/views/settings-page.php` | Create |
| `rfq-intake/assets/materials/default.json` | Create |
| `frontend/package.json` | Create |
| `frontend/vite.config.ts` | Create |
| `frontend/src/main.tsx` | Create — mount React |
| `frontend/src/App.tsx` | Create |
| `frontend/src/api/client.ts` | Create |
| `frontend/src/state/FormContext.tsx` | Create |
| `frontend/src/steps/*.tsx` | Create — 5 steps + success |
| `frontend/src/lib/uploadFile.ts` | Create |
| `frontend/src/hooks/useAutosave.ts` | Create |
| `tests/php/ManifestValidatorTest.php` | Create |
| `tests/php/PostalCodeTest.php` | Create |
| `composer.json` | Create — PHP deps + autoload PSR-4 `RFQ\\` |
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

11. **Content-Type on S3 PUT:** Browser must send the same `Content-Type` used when generating the presigned URL.

12. **UTC dates:** Server-side delivery date validation uses UTC date, not WP timezone.

---

## 7. Acceptance Criteria

### WordPress plugin

- [ ] `[rfq_form]` renders React form on a WP page; scripts not loaded on other pages
- [ ] Admin can configure S3, emails, Airtable URL, optional ERP webhook without redeploy
- [ ] Secrets are write-only in admin and encrypted in DB
- [ ] `GET /health` returns 200 only when S3 credentials work
- [ ] Full happy path: contact → upload part file → metadata → global → submit → receipt number
- [ ] Success screen shows SVG, thank-you copy, muted `Ref: RFQ-...`
- [ ] Submit retry after simulated network failure returns same receipt number (idempotent)
- [ ] Page refresh starts new session; prior warm lead row remains in DB
- [ ] Health failure within 3s shows Airtable embed
- [ ] >20 parts blocked in UI and rejected on submit
- [ ] Rate limit: 11th session from same IP in 1 hour → 429
- [ ] Invalid manifest returns 422 with field errors
- [ ] Missing S3 file at submit returns error identifying missing keys
- [ ] Webhook POST fires on submit when URL configured; submit still succeeds when webhook target is down

### ERP (separate repo)

- [ ] Webhook verifies HMAC signature
- [ ] Poll worker imports receipt within 5 minutes when webhook skipped
- [ ] Duplicate import attempts create one quote only
- [ ] Import copies only manifest-referenced keys to quote storage
- [ ] Import deletes `intake/{session_id}/` prefix after success
- [ ] Import skips sessions with manifest but no receipt

---

## 8. Testing Plan

### PHPUnit (`tests/php/`)

| Test class | Cases |
|------------|-------|
| `ManifestValidatorTest` | valid manifest passes; missing email fails; custom tolerance without detail fails; qty 0 fails; 21 parts fails; past delivery date fails; invalid postal code fails |
| `PostalCodeTest` | US 5/9 digit; CA format; rejects garbage |
| `PhoneValidatorTest` | 10 digits pass; 9 digits fail; phone set requires country code 1 |
| `ReceiptNumberTest` | sequential format `RFQ-YYYYMMDD-000001`; daily rollover |

Run: `composer test` (configure phpunit.xml).

### Manual — plugin staging

1. Configure admin settings with staging S3 bucket + blank webhook URL.
2. Place `[rfq_form]` on a test page.
3. Complete RFQ with one part + one drawing PDF.
4. Verify objects in S3 under `intake/{session_id}/`.
5. Submit; verify `meta/manifest.json`, `meta/receipt.json`, DB row `status=submitted`.
6. Refresh page — form resets; DB shows two session rows (first abandoned/submitted).
7. Break S3 secret in admin → health fails → Airtable iframe appears.
8. Submit with network throttling; kill request; retry → same receipt number.
9. Check NDA checkbox → sales email notice visible; manifest has `nda_required: true`.

### Manual — ERP staging

1. Configure webhook URL + shared secret on WP and ERP.
2. Submit RFQ → quote appears in ERP within seconds.
3. Disable webhook URL → submit → quote appears within 5-minute poll.
4. Confirm intake prefix deleted after import.
5. Re-run import job on same receipt → no duplicate quote.

### Integration checklist before production

- [ ] Production S3 bucket is private (anonymous GET returns 403)
- [ ] Production WP admin settings filled (S3, emails, ERP webhook)
- [ ] Airtable fallback URL tested
- [ ] JWT + encryption keys rotated from dev defaults
- [ ] ERP backlog alert configured (PRD §5.1)

---

## Suggested milestones / PR slicing

| Milestone | Scope | Demo |
|-----------|--------|------|
| M1 | Phase 0–2 + health + sessions | REST client can create session |
| M2 | Phase 3 + lead + upload-urls | curl uploads file to S3 via presigned URL |
| M3 | Phase 4 submit | curl submit returns receipt; S3 has receipt.json |
| M4 | Phase 5–6 React Steps 1–2 | Upload UI works E2E |
| M5 | Phase 6 Steps 3–5 + success | Full form E2E |
| M6 | Phase 7 ERP import | Quote in ERP from receipt |

Ship M3 to staging before investing in React polish — proves durability story early.

# ERP integration: RFQ intake on S3

This is the contract between the WordPress **RFQ Intake** plugin and the ERP. The plugin accepts the customer submission. The ERP creates the quote. They share one private S3 bucket and an optional webhook. The ERP never reads or writes the WordPress database.

Product source: `Planning/PRD.md` §3.3–§3.5, `Planning/ADD.md` §2 and §7–§10, `CONTEXT.md`.

## Completion signal

A customer is finished only when this object exists:

```
intake/{session_id}/meta/receipt.json
```

That file is the completion marker. There is no `completed`, `status`, or `submitted` field inside `manifest.json` or `draft.json`. Those files can exist while the customer is still in the form, or after a submit that failed partway through.

Import a session only when `receipt.json` is present. If the prefix has part files, drawing files, `draft.json`, or even `manifest.json`, and `receipt.json` is missing, skip it. Do not create parts or a quote. This is acceptance criterion **AC-ERP-007**.

`receipt.json` is written only after all of the following succeed:

1. The session JWT is valid and matches the session.
2. The WordPress session is still `draft` (a repeat submit returns the existing receipt and does not write a second one).
3. The manifest passes field validation.
4. Every file key in the manifest exists in S3, belongs to this session, is in the correct `parts/` or `drawings/` folder, is within the size limit, and has an allowed content type when S3 reports one.
5. `manifest.json` has been written.
6. A receipt number has been allocated.

The customer sees the success screen only after that write returns a receipt number. The webhook fires only after the receipt is in S3 and the WordPress index row is updated to `submitted`.

`receipt.json` is immutable. A retry of the same session returns the same receipt number and does not rewrite the object.

### Objects that are not completion

| Object | When it appears | ERP action |
|--------|-----------------|------------|
| `parts/` and `drawings/` files | As the customer uploads, before submit | Ignore until a receipt exists |
| `meta/draft.json` | Autosave while the form is open | Ignore. It is in-progress metadata and is not a submission |
| `meta/manifest.json` without `meta/receipt.json` | Submit started and then failed after the manifest write | Skip. The customer likely saw an error and may retry |
| `meta/receipt.json` | Submit completed | Import |

WordPress logs and alerts when a manifest sits without a receipt for more than 15 minutes. That alert is for operations. It is not a signal to import.

## What the plugin already does

The plugin owns the public intake path. The ERP is not on that path, so an ERP deploy does not block a customer submit.

Implemented in this repository:

- Anonymous intake session (`session_id` UUID) and a 1-hour JWT.
- Contact capture, file upload URLs, draft autosave, and final submit under `/wp-json/rfq/v1/`.
- Browser uploads go directly to S3 with pre-signed PUT URLs. Keys are generated on the server. The browser never chooses a key and never receives GET, LIST, or DELETE URLs.
- Final submit validates the manifest, checks every declared object with `HeadObject`, writes `manifest.json`, allocates `RFQ-YYYYMMDD-######`, writes `receipt.json`, then sets the WordPress row to `status = submitted`.
- Best-effort webhook after that index write. Webhook failure does not fail the customer response and is not retried by WordPress.
- Daily maintenance that flags orphan manifests and lists unreceipted prefixes older than 30 days. It does not delete submitted prefixes. The ERP deletes a prefix after a successful import.

Not implemented here, and not the plugin's job:

- Creating a quote, customer, or part record in the ERP.
- Copying files into canonical quote storage.
- Deciding ERP folder structure, part numbers, or revision scheme.
- Reading import status back into WordPress. WordPress stays at `submitted`.

## Bucket layout

The intake prefix is private. Anonymous read and list are denied. The ERP uses its own server-side credentials with read access to `intake/` and delete access to a prefix after import.

```
intake/
  {session_id}/                  UUID v4
    meta/
      draft.json                 autosave; ignore for import
      manifest.json              final payload; read only with a receipt
      receipt.json               completion marker and import key
    parts/
      {file_id}_{sanitized_filename}
    drawings/
      {file_id}_{sanitized_filename}
```

- `session_id` and `file_id` are UUID v4.
- `sanitized_filename` is the original filename with characters other than letters, digits, `.`, and `-` replaced by `_`, truncated to 100 characters.
- Keys are always `intake/{session_id}/parts/...` or `intake/{session_id}/drawings/...`.

### Receipt

```json
{
  "receipt_number": "RFQ-20260623-000042",
  "session_id": "550e8400-e29b-41d4-a716-446655440000",
  "submitted_at": "2026-06-23T14:30:00Z",
  "manifest_key": "intake/550e8400-e29b-41d4-a716-446655440000/meta/manifest.json"
}
```

| Field | Meaning |
|-------|---------|
| `receipt_number` | `RFQ-{YYYYMMDD}-{6-digit daily sequence}`, date in UTC. Store this as `source_receipt_number` on the quote. It is not the ERP quote number. |
| `session_id` | Intake session. The S3 prefix is `intake/{session_id}/`. |
| `submitted_at` | ISO-8601 UTC time the receipt was written. |
| `manifest_key` | Exact key to read. Do not guess a different path. |

### Manifest

Written once, at submit, with the payload the customer confirmed. Shape:

```json
{
  "session_id": "550e8400-e29b-41d4-a716-446655440000",
  "contact": {
    "first_name": "Jane",
    "last_name": "Smith",
    "email": "jane@example.com",
    "company": "Acme Corp",
    "phone": "2025550105",
    "phone_country_code": "1",
    "job_title": null
  },
  "parts": [
    {
      "part_id": "6ba7b810-9dad-11d1-80b4-00c04fd430c8",
      "part_file_key": "intake/{session_id}/parts/{file_id}_bracket.step",
      "drawing_file_keys": [
        "intake/{session_id}/drawings/{file_id}_bracket.pdf"
      ],
      "material": "6061 Aluminum",
      "tolerance": "standard",
      "tolerance_detail": null,
      "threads_features": null,
      "quantity": 25,
      "target_unit_price": 12.5,
      "notes": null
    }
  ],
  "global": {
    "required_delivery_date": "2026-07-15",
    "lead_time_preference": "standard",
    "shipping_destination": { "postal_code": "97201" },
    "po_number": null,
    "nda_required": false,
    "notes": null
  }
}
```

**Contact**

| Field | Required | Notes |
|-------|----------|-------|
| `first_name`, `last_name`, `email` | Yes | Stored as the customer typed them, after trim. Email is not lowercased. |
| `company` | No | `null` when blank. |
| `phone` | No | National significant digits only, no formatting. `null` when omitted. |
| `phone_country_code` | With phone | ITU calling code, 1–4 digits, no `+`. `null` when phone is omitted. Display as `+{code}{phone}`. |
| `job_title` | No | Always `null` in V1. The form does not collect it. |

**Parts** (1 to 20)

| Field | Required | Notes |
|-------|----------|-------|
| `part_id` | Yes | UUID, unique inside this manifest. Client-generated. Use it to keep the part file and its drawings together. It is not an ERP part number. |
| `part_file_key` | Yes | One CAD file. Folder is `parts/`. Content type is `application/octet-stream`. Max 500 MiB (524,288,000 bytes). The extension is in the filename, not the content type. |
| `drawing_file_keys` | Yes, may be `[]` | Zero or more drawings for this part. Folder is `drawings/`. Content type is `application/pdf`, `image/png`, or `image/jpeg`. Max 50 MiB (52,428,800 bytes) each. |
| `material` | Yes | Free-text string. The form suggests a catalog (`1018 Steel`, `6061 Aluminum`, `7075 Aluminum`, `304 Stainless`, plus admin overrides) but the customer can type anything. Store the string. Do not require a catalog id; the manifest has none. |
| `quantity` | Yes | Integer ≥ 1. |
| `tolerance` | Yes | `standard`, `precision`, or `custom`. |
| `tolerance_detail` | When `custom` | Non-empty string. `null` otherwise. |
| `threads_features` | No | String or `null`. |
| `target_unit_price` | No | Number ≥ 0 with at most 2 decimal places, or `null` / omitted. Customer target, not a quoted price. |
| `notes` | No | String or `null`. |

**Global**

| Field | Required | Notes |
|-------|----------|-------|
| `required_delivery_date` | Yes | `YYYY-MM-DD`. The customer's requested need-by date for quoting. Not a promised ship date. Validated as today or later in UTC at submit time. |
| `lead_time_preference` | Yes | `no_rush`, `standard`, `target_date`, `expedited`, or `economy`. `target_date` means meet `required_delivery_date`. |
| `shipping_destination.postal_code` | Yes | US ZIP (`97201` or 9 digits `972011234`) or Canadian postal code with the space removed (`K1A0B1`). V1 has no street address and no destinations outside the US and Canada. |
| `po_number` | No | String or `null`. |
| `nda_required` | Yes | Boolean. `true` means exclude the parts from marketing use. It is not a signed NDA. |
| `notes` | No | String or `null`. |

## How the ERP should discover work

Two triggers call the same import. Both must be idempotent. A receipt creates one quote.

### 1. Webhook (fast path)

WordPress POSTs once, after the receipt and the WordPress index row exist. Timeout is 5 seconds. The call is fire-and-forget. WordPress does not retry.

Configure the URL and shared secret in the WordPress plugin settings. If the URL is blank, WordPress skips the webhook and the poll worker is the only trigger.

```
POST {erp webhook url}
Content-Type: application/json
X-RFQ-Signature: {hex HMAC-SHA256 of the raw body}
```

```json
{
  "receipt_number": "RFQ-20260623-000042",
  "session_id": "550e8400-e29b-41d4-a716-446655440000",
  "receipt_key": "intake/{session_id}/meta/receipt.json"
}
```

Verify `X-RFQ-Signature` with HMAC-SHA256 over the raw request body and the shared secret (**AC-ERP-002**). Reject a missing or invalid signature before any S3 read. The secret must match the WordPress environment (staging secret with staging, production with production).

The body is a pointer. Read `receipt.json` from `receipt_key`, then follow `manifest_key`. Do not trust the webhook body as the quote payload.

### 2. S3 poll (reliable path)

About every 5 minutes, list `intake/` for keys ending in `meta/receipt.json` (**AC-ERP-003**). For each receipt, import when no quote already has that `source_receipt_number`.

This path covers a blank webhook URL, a failed POST, and ERP downtime during submit. The customer already has a receipt number in those cases. The files stay under `intake/` until import succeeds.

## Import sequence

For each receipt that has no quote yet:

1. Read `receipt.json`.
2. Read the object at `manifest_key`.
3. If a quote with `source_receipt_number = receipt_number` already exists, skip creation. If the intake prefix is still there, delete it and return success (**AC-ERP-004**).
4. Create the quote in ERP Postgres. Allocate a new ERP quote number. Set `source_receipt_number` to `receipt_number`. Write contact, global metadata, and one ERP part per manifest `parts[]` entry.
5. Copy **only** the keys named by that quote: each `part_file_key` and each entry in `drawing_file_keys`. Put them in canonical quote storage, for example `quotes/{quote_number}/`. Do not copy `draft.json`, and do not copy any object in the prefix that the manifest does not name (**AC-ERP-005**).
6. Delete the entire `intake/{session_id}/` prefix only after steps 4 and 5 succeed (**AC-ERP-006**). A failed import must leave the prefix in place so the next poll or webhook can retry.

Unreferenced objects are normal. If the customer removed a file in the form, the bytes can still sit in the prefix. The manifest is the list of files that belong to the quote. Deleting the prefix at step 6 removes those orphans.

### Sorting files into the ERP

Group by manifest part, not by S3 folder listing order.

For each `parts[]` entry, in array order:

- The object at `part_file_key` is the CAD model for `part_id`.
- Every object in `drawing_file_keys` is a drawing for that same `part_id`.
- `material`, `quantity`, `tolerance`, `tolerance_detail`, `threads_features`, `target_unit_price`, and `notes` belong to that part.

`part_id` identifies the line inside this RFQ. The ERP assigns its own part and quote identifiers. Keep `part_id` if you need to trace a line back to the intake package.

Filename is the suffix after `{file_id}_`. Use it as the original filename. Part files will not tell you the CAD format from `Content-Type`; they are all `application/octet-stream`.

Quote-level fields come from `contact` and `global`, not from individual part objects.

## Idempotency and failure

| Situation | What to do |
|-----------|------------|
| Webhook and poll both see the same receipt | Create one quote. Key the check on `source_receipt_number`. |
| Import fails after the quote row exists but before files are copied | Retry the file copy. Do not create a second quote. Do not delete the intake prefix. |
| Import fails before the quote exists | Leave S3 untouched. The next cycle retries. |
| Prefix already deleted and the quote exists | No-op success. |
| Manifest present, receipt absent | Do not import. |
| Receipt present, WordPress database out of date | Still import. S3 is the source of truth. The ERP has no WordPress credentials. |

WordPress will not mark a receipt imported. Import state lives in ERP Postgres.

## Retention

- After a successful import, the ERP deletes `intake/{session_id}/`.
- Prefixes with no `receipt.json` that are older than 30 days are cleanup candidates. A weekly job (ERP or ops) lists `intake/` and deletes those prefixes. S3 lifecycle rules cannot see that a sibling `receipt.json` is missing.
- If import keeps failing, leave the prefix and alert. The receipt is the customer's proof of submit.

Suggested ERP alert: more than 20 `receipt.json` objects with no matching quote, and the oldest is more than 1 hour old.

## ERP checklist

- [ ] S3 credentials can read `intake/` and delete a session prefix. The bucket stays private.
- [ ] Webhook route checks `X-RFQ-Signature` and then runs the import sequence.
- [ ] Poll worker, about every 5 minutes, lists `meta/receipt.json` and runs the same sequence.
- [ ] Importer refuses a prefix that has no `receipt.json`.
- [ ] Quote stores `source_receipt_number` and is unique on that value.
- [ ] Files copied are only `part_file_key` and `drawing_file_keys`, grouped by `part_id`.
- [ ] `intake/{session_id}/` is deleted only after the quote and those copies succeed.
- [ ] WordPress is not queried for import state.

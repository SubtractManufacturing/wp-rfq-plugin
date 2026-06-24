# RFQ Intake

Public-facing Request for Quote submission on the company WordPress site. Customers upload CAD files and metadata; the system writes a durable receipt before showing success. The internal ERP imports receipts asynchronously.

## Language

**Intake Session**:
An anonymous, in-browser working period for one RFQ attempt, identified by a `session_id` and scoped JWT. It exists from form load until the customer submits, abandons, or refreshes the page.
_Avoid_: Draft, visit, form session

**Warm Lead**:
Contact information captured when the customer completes Step 1, persisted in WordPress even if they never submit the RFQ. Required: first name, last name, email. Optional: company, phone (10-digit US/CA national number with country code metadata). Same field set as manifest `contact`.
_Avoid_: Partial submission, lead record

**Contact**:
The customer's identity fields for an RFQ (`first_name`, `last_name`, `email`, `company`, `phone`, `job_title`). Mirrored on the warm lead row, autosave draft, and submit manifest. Only the first five are collected in V1 UI; `job_title` is schema-only.
_Avoid_: Lead, customer profile

**Receipt**:
The durable proof that a submitted RFQ passed full validation. Identified by a receipt number (e.g. `RFQ-20260623-000042`). The customer sees success only after a receipt exists. WordPress final state is `submitted`; the ERP imports from S3 without updating WordPress.
_Avoid_: Confirmation, submission ID, quote number

**Import**:
The ERP process that reads a receipt and manifest from S3, creates a Quote in Postgres, copies files to canonical storage, and deletes the intake prefix. Triggered by webhook (fast) or S3 poll worker (~5 min, reliable).
_Avoid_: Sync, processing, ingest job

**Manifest**:
The complete structured payload (contact, parts, files, global metadata) written at final submit. Lists exactly which S3 objects belong to the RFQ; unlisted objects in the session prefix are ignored. A manifest without a receipt is an incomplete submission, not a customer-facing success.
_Avoid_: Submission payload, form data export

**Quote**:
The official business record created in the ERP after import. Has its own quote number, distinct from the receipt number.
_Avoid_: RFQ, receipt

**Part**:
One line item in an RFQ: a CAD part file (required), optional drawing files, and per-part metadata (material, quantity, etc.). Identified within a session by a client-generated `part_id` from Step 2 through submit.
_Avoid_: Line item, SKU, assembly

**Shipping destination**:
In V1, the US or Canadian postal code where finished parts should ship. Full international addresses are not collected online; customers outside North America are directed to the international RFQ email.
_Avoid_: Address, ship-to, delivery location

**Material**:
The metal or substance a part should be made from, stored as a free-text string on each part in the manifest. The form offers a simple dropdown and type-ahead from a plugin-shipped catalog with WP admin overrides; custom values are always allowed.
_Avoid_: Alloy code, stock type, material SKU

**Required delivery date**:
The customer's requested need-by date for quoting — not a contractual ship date. Captured in global RFQ metadata; must be today or later.
_Avoid_: Ship date, promise date, due date (contractual sense)

**Lead time preference**:
How the customer wants production timing prioritized: `no_rush`, `standard`, `target_date` (meet the requested delivery date), `expedited`, or `economy`.
_Avoid_: Priority, urgency level, SLA

**Treat as NDA** (`nda_required`):
When true, internal teams exclude the parts from social media and marketing use. Not a formal countersigned NDA workflow — customers needing that must email sales directly.
_Avoid_: NDA flag, confidentiality toggle

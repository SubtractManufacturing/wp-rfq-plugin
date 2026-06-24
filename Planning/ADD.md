# Architecture Decision Document: RFQ Intake Flow

**Project:** Custom RFQ Intake System
**Status:** Finalized
**Last Updated:** 2026-06-23

This document explains *why* the architecture is designed the way it is. It is intended for engineers working on the system who encounter a design choice that seems non-obvious or overly cautious, and want to understand the reasoning before considering changes. The PRD describes *what* to build; this document explains *why*.

---

## Decision Index

1. [WordPress as the public intake API, not the ERP](#1-wordpress-as-the-public-intake-api-not-the-erp)
2. [S3 as the canonical receipt store, not the WordPress database](#2-s3-as-the-canonical-receipt-store-not-the-wordpress-database)
3. [Anonymous sessions with short-lived JWTs, not user accounts](#3-anonymous-sessions-with-short-lived-jwts-not-user-accounts)
4. [Direct browser-to-S3 uploads via pre-signed URLs](#4-direct-browser-to-s3-uploads-via-pre-signed-urls)
5. [Server-side S3 key generation — never client-supplied keys](#5-server-side-s3-key-generation--never-client-supplied-keys)
6. [HeadObject validation before writing the receipt](#6-headobject-validation-before-writing-the-receipt)
7. [ERP is an async consumer, never in the submission critical path](#7-erp-is-an-async-consumer-never-in-the-submission-critical-path)
8. [S3 poll + webhook notify — ERP owns import, no ERP↔WP DB coupling](#8-s3-poll--webhook-notify--erp-owns-import-no-erpwp-db-coupling)
9. [Receipt written to both S3 and WordPress DB](#9-receipt-written-to-both-s3-and-wordpress-db)
10. [Manifests without receipts are treated as incomplete by the ERP](#10-manifests-without-receipts-are-treated-as-incomplete-by-the-erp)
11. [Airtable as a warm fallback, not a backup system](#11-airtable-as-a-warm-fallback-not-a-backup-system)
12. [JWT stored in React memory, not browser storage](#12-jwt-stored-in-react-memory-not-browser-storage)
13. [Page refresh abandons the session — no client-side draft resume](#13-page-refresh-abandons-the-session--no-client-side-draft-resume)
14. [Rate limiting session creation, not uploads](#14-rate-limiting-session-creation-not-uploads)
15. [No draft resumption in V1](#15-no-draft-resumption-in-v1)
16. [Autosave does not block the user](#16-autosave-does-not-block-the-user)
17. [Re-submission of the same session is idempotent](#17-re-submission-of-the-same-session-is-idempotent)
18. [Part-scoped file uploads in Step 2](#18-part-scoped-file-uploads-in-step-2)
19. [Manifest is the file source of truth; upload-only S3 for customers](#19-manifest-is-the-file-source-of-truth-upload-only-s3-for-customers)
20. [Plugin secrets encrypted at rest, write-only in admin](#20-plugin-secrets-encrypted-at-rest-write-only-in-admin)
21. [Private S3 bucket — no public object access](#21-private-s3-bucket--no-public-object-access)
22. [TypeScript and Tailwind for the React form](#22-typescript-and-tailwind-for-the-react-form)
23. [Production automated testing before release](#23-production-automated-testing-before-release)

---

## 1. WordPress as the public intake API, not the ERP

**Decision:** The WordPress plugin handles session creation, JWT issuance, pre-signed URL generation, manifest validation, and receipt writing. The ERP is not involved in the submission critical path.

**Why it seems wrong:** The ERP already owns all the business logic around quoting, customers, and order processing. It would seem natural to build the intake API there.

**Why we chose this:** The ERP deploys approximately 10 times per week. Each deployment causes several minutes of downtime. This is acceptable for internal staff, but unacceptable for a public-facing customer experience. If a customer spends 20 minutes filling out an RFQ and clicks Submit during an ERP deployment, we either lose their submission or show them an error after all that work — both are bad outcomes.

WordPress is already the public availability boundary. If the customer can reach the RFQ page at all, they're already on the WordPress server. Extending the WordPress plugin to handle intake means the intake API shares the availability characteristics of the page itself. There's no additional infrastructure to keep available, and the plugin changes slowly relative to the ERP.

The key insight is that WordPress doesn't need to know anything about pricing, production planning, or quote business logic. It only needs to issue tokens, proxy pre-signed URL requests, and validate that the expected files and fields are present before writing a receipt. That's a limited, stable responsibility that won't require frequent changes.

---

## 2. S3 as the canonical receipt store, not the WordPress database

**Decision:** `receipt.json` written to S3 is the authoritative record of a submitted RFQ. The WordPress DB row is an index for lookups, not the source of truth.

**Why it seems wrong:** WordPress already has a database. Why write to S3 at all? Why not just use the WP DB as the receipt store?

**Why we chose this:** The core failure mode we are protecting against is: *the customer believes they submitted, but we never received it*. To protect against this, we need the receipt to survive failures in any single component.

If WordPress DB is the only receipt store, a MySQL failure or WP DB write error at the moment of submission means we have no receipt, even though all the files are safely in S3. We would have to tell the customer to resubmit.

By writing `receipt.json` to S3 first (step 7) and then the WP DB row (step 8), we ensure that even if the DB write fails, a durable record exists in S3. The ERP import worker can recover from S3 directly. The WP DB row is rebuilt from S3 if necessary.

The secondary benefit: S3 object storage is extremely durable (11 nines) and does not have the same maintenance and failure modes as a relational database. It is the right place for the primary durability guarantee.

---

## 3. Anonymous sessions with short-lived JWTs, not user accounts

**Decision:** RFQ submission is fully anonymous. No customer account is required. A session JWT is issued on form load and scoped to that session.

**Why it seems wrong:** User accounts would make it easier to track leads, enable re-entry, and associate multiple RFQs with one customer over time. Many enterprise systems require login.

**Why we chose this:** The goal is to minimize friction for new customers submitting their first RFQ. Requiring account creation would add a registration step that many potential customers would abandon. This is an outbound lead capture flow, not a customer portal.

We still capture the customer's contact information (name, email, and optional company/phone) as the first step — this is our warm lead. We don't need an account to have a durable record of who they are.

The JWT is not a substitute for authentication. It is a tamper-proof, scoped token that ensures:
- A customer can only upload to their own session's S3 prefix.
- A customer cannot call the submit endpoint for a session that isn't theirs.
- The token has a short enough lifespan that a leaked token has limited exploitability.

If we later add a customer portal with account login, that is additive and does not require changes to the intake flow — the receipt number becomes the link between the anonymous submission and the account.

---

## 4. Direct browser-to-S3 uploads via pre-signed URLs

**Decision:** Files are uploaded directly from the customer's browser to S3 using pre-signed PUT URLs. They do not pass through the WordPress server or the ERP.

**Why it seems wrong:** Routing uploads through the server gives you more control: you can validate the file server-side before it lands in storage, you can log every byte, and you can apply more aggressive access controls.

**Why we chose this:** CAD files (STEP, SolidWorks) are frequently 50–200 MB and can be larger. Routing large binary files through a WordPress PHP process is slow, expensive, and risky — PHP has memory limits and execution time limits that make it unsuitable as a file proxy for large uploads. The WordPress server would become a bottleneck and a single point of failure for uploads.

Pre-signed URLs are the industry-standard pattern for browser-to-object-storage uploads. The S3 policy conditions on the URL enforce the key prefix, content-type, and size limits server-side without the WordPress server needing to touch the file bytes.

The plugin issues **PUT-only** pre-signed URLs. Customers never receive delete or list access to the bucket — removing a file in the UI does not remove it from S3; the manifest and ERP import define which objects matter.

The trade-off accepted here: we cannot do deep content validation (e.g., verify that the file is actually a valid STEP file, not a malicious payload disguised with a STEP extension) at upload time. We accept this because:
- The files land in a staging prefix, not in production storage.
- The ERP import process can run content validation before moving files to canonical storage.
- The attack surface is limited: an attacker would need a valid session JWT to even generate a pre-signed URL.

---

## 5. Server-side S3 key generation — never client-supplied keys

**Decision:** When the customer requests a pre-signed upload URL, the WordPress plugin generates the S3 key and returns it alongside the URL. The client uses the returned key. The client never supplies a key.

**Why it seems wrong:** It would be simpler to let the client specify the filename and construct the key. This is how many simpler upload systems work.

**Why we chose this:** If the client can supply a key, an attacker with a valid JWT could craft a request to upload to `intake/{their_session_id}/../{some_other_session_id}/meta/receipt.json` and potentially overwrite another session's receipt, or inject a fraudulent receipt.

S3 path traversal via `../` is typically neutralized by S3 itself, but the broader principle holds: any client-controlled string that ends up in an S3 key is a potential injection vector. By generating keys entirely server-side (a UUID prefix + sanitized filename), we eliminate this class of attack entirely. The pre-signed URL is then scoped to that exact key; the client cannot deviate from it.

---

## 6. HeadObject validation before writing the receipt

**Decision:** Before writing `manifest.json` or `receipt.json`, the WordPress plugin calls S3 `HeadObject` on every file key declared in the manifest to confirm the objects actually exist.

**Why it seems wrong:** The client already knows whether uploads succeeded — it got a 200 from S3 for each one. Checking again from the server is redundant and adds latency to the submit path.

**Why we chose this:** The client's report of upload success is not trustworthy. A client could:
- Send a manifest listing files that were never uploaded (accidentally or maliciously).
- Experience a network failure mid-upload that resulted in a partial or zero-byte object, while the client-side code incorrectly reported success.
- Construct a manifest with file keys from a different session.

The `HeadObject` check is the server's ground truth. It is the only moment in the flow where we can confirm, with certainty, that the promised files are actually in S3 before we commit to the customer that their submission is complete.

The latency cost is acceptable: `HeadObject` is a cheap S3 metadata-only operation (no data transfer). A typical RFQ with 5 files would add approximately 250–500ms of latency to the submit path. This is acceptable on a form that has taken the customer 10–20 minutes to complete.

---

## 7. ERP is an async consumer, never in the submission critical path

**Decision:** The ERP has no role in accepting or validating a customer's RFQ submission. It only processes submissions after the fact, asynchronously.

**Why it seems wrong:** The ERP is where the "real" business data lives. Shouldn't the canonical record be created there at the moment of submission?

**Why we chose this:** The ERP deploys frequently and has downtime. See Decision 1. But beyond availability, there's a design principle at play: the ERP's job is to process quotes, not to accept raw customer submissions. The intake flow (session management, file upload, manifest assembly) is a different concern from quote processing (pricing, BOM creation, engineering review routing).

By making the ERP a consumer of intake packages rather than a participant in intake, we get:
- Deployment independence: ERP can be down, redeploy, roll back, or be entirely replaced without affecting customer submission.
- Clear interface: the ERP import contract is simply "read a `receipt.json` and its associated `manifest.json` from S3." This contract is stable even as both systems evolve.
- Retry-ability: if ERP import logic has a bug, we can fix the bug and re-run the import on any historical receipt without asking the customer to resubmit.

---

## 8. S3 poll + webhook notify — ERP owns import, no ERP↔WP DB coupling

**Decision:** After submit, WordPress stops at `status = 'submitted'`. The ERP discovers new work by scanning S3 for `receipt.json` on a ~5-minute schedule. WordPress also sends a best-effort webhook POST to the ERP for fast import when the ERP is online. The ERP never reads or writes the WordPress database. Import state (which receipts became quotes) lives in ERP Postgres only.

**Why it seems wrong:** Polling S3 is slower and costs LIST operations. A webhook without retry is unreliable. Updating a manifest or calling back into WordPress to mark `imported` would keep a single status trail.

**Why we chose this:** S3 is already the durable contract between systems (`receipt.json` + `manifest.json`). The ERP can import with only S3 and its own database — no WP DB credentials, no cross-system status sync, no mutating immutable receipts. The webhook is a optional accelerator, not a dependency: if it fails during an ERP deploy, the poll worker catches the receipt within minutes. WordPress does not implement retry queues in V1 — that would reimplement a message broker in PHP.

**Trade-off accepted:** WordPress cannot show "imported into ERP" without asking the ERP. WP ops see `submitted` only. Import backlog alerts live in the ERP. Webhook URL and secret are configured in WP admin (managed hosting, no env vars) and may be left blank for poll-only operation.

---

## 9. Receipt written to both S3 and WordPress DB

**Decision:** The receipt is written to S3 (`receipt.json`) first, then indexed in the WordPress MySQL database (`rfq_sessions` row with `status = 'submitted'`).

**Why it seems wrong:** Writing to two places introduces inconsistency risk. What if the S3 write succeeds and the DB write fails? Now the data is split across two systems.

**Why we chose this:** The two stores serve different purposes and the inconsistency case is handled explicitly.

S3 is durable storage. `receipt.json` in S3 is the authoritative record of submission. It never changes after it's written. The ERP import worker reads from S3 only.

The WP DB row is a **WordPress-local index**: warm leads, receipt lookup for WP admin/ops, and submit idempotency. It is not the ERP import queue.

The inconsistency case — S3 write succeeds, DB write fails — means the customer may not see a receipt number in the response even though S3 has the receipt. The ERP can still import from S3; ops can reconcile via S3. The alert on manifest-without-receipt covers the inverse partial-write case.

We write S3 first (not DB first) precisely because S3 is the durable store and the ERP's import contract. If DB write succeeds and S3 write fails, we have a phantom receipt row pointing to files we can't confirm are there. If S3 write succeeds and DB write fails, we have real files and a real receipt that the ERP can still process.

---

## 10. Manifests without receipts are treated as incomplete by the ERP

**Decision:** If the ERP import worker finds a `manifest.json` in S3 without a corresponding `receipt.json`, it skips that session and does not create a quote.

**Why it seems wrong:** The manifest contains all the data. Why not import it anyway? The customer uploaded everything; why withhold the quote?

**Why we chose this:** The receipt is the signal that the WordPress plugin has completed its full validation sequence — field validation, `HeadObject` checks, sequence number assignment — and committed to the customer that their submission is accepted. A manifest without a receipt means the submission sequence was interrupted partway through. The customer may not know their submission completed. They may have seen an error and be about to retry.

If the ERP imported an incomplete submission, it could create a duplicate quote when the customer successfully retries. The receipt is a coordination mechanism: once it exists, it's the single submission record. Before it exists, the submission is in flight.

The alert on "manifest without receipt after 15 minutes" (see PRD section 5.1) exists precisely to catch cases where the submission sequence failed after writing the manifest but before writing the receipt, so the operations team can investigate and manually complete the intake if needed.

---

## 11. Airtable as a warm fallback, not a backup system

**Decision:** If the WordPress health check fails on page load, the React form renders the existing Airtable embed iframe instead. Airtable is not kept as a parallel submission path.

**Why it seems wrong:** A hot fallback that requires the customer to fill out a completely different form is a poor experience. Why not just queue the submission locally (IndexedDB) and send it when the server recovers?

**Why we chose this on the queue idea:** Client-side queuing (IndexedDB + background sync) is fragile. Browsers kill background processes, users close tabs, and the sync may never fire. The failure mode of a "queued" submission that never actually reaches the server is exactly the silent loss scenario we are most trying to avoid. A customer would believe they submitted; we would have no record.

**Why we chose this on the Airtable UX:** The Airtable form is the existing intake path that customers are already familiar with. It is ugly and non-customizable, but it works. The expectation is that WordPress being down is a rare event (maintenance windows are scheduled). A customer who hits the Airtable form is getting a functional, if less polished, experience.

The key design principle: the fallback activates before the customer has entered any data. The health check runs on page load, before the form renders. The customer never experiences a "degraded mode mid-session." They either get the full custom experience or the Airtable experience from the start.

---

## 12. JWT stored in React memory, not browser storage

**Decision:** The session JWT is stored as React state. It is never written to `localStorage` or `sessionStorage`.

**Why it seems wrong:** Browser storage would persist the JWT across page refreshes, avoiding session loss on reload.

**Why we chose this:** JWTs in browser storage are readable by any JavaScript on the page, including injected scripts from compromised third-party dependencies (a common XSS vector on WordPress sites). A stolen JWT lets an attacker upload files to the victim's session prefix or hijack their in-progress submission.

In-memory storage limits exposure to the active page lifetime. An XSS attack can still target the current session, but cannot exfiltrate the token for reuse after the tab closes.

**Trade-off accepted:** Page refresh drops the JWT and abandons the session (see Decision 13).

---

## 13. Page refresh abandons the session — no client-side draft resume

**Decision:** A full page reload invalidates the intake session. The form creates a new session and the customer starts over. No form state is restored from `localStorage`, `sessionStorage`, or a server replay endpoint.

**Why it seems wrong:** Accidental refresh after 15 minutes of data entry feels punishing. Autosave already writes drafts to the server — why not rehydrate?

**Why we chose this:** Rehydrating after refresh requires either persisting the JWT (rejected in Decision 12) or a new server endpoint to re-bind an old session without the original token. Partial rehydration (metadata without files) creates a broken state: the form shows progress but submit fails because file keys belong to the abandoned session.

Starting fresh on refresh is honest UX: the customer knows they must re-enter data and re-upload files. The warm lead from Step 1 on the abandoned session is still captured in WP DB. Server-side autosave drafts remain for operations recovery, not customer resume.

Orphaned S3 objects under abandoned session prefixes are cleaned up by lifecycle rules.

---

## 14. Rate limiting session creation, not uploads

**Decision:** Rate limiting is applied at the session creation endpoint (`POST /sessions`) per IP, not at the file upload endpoint.

**Why it seems wrong:** File uploads are where the real resource cost is. Wouldn't it make more sense to rate-limit uploads?

**Why we chose this:** Pre-signed upload URLs expire after 30 minutes and are scoped to a specific S3 key. An attacker who obtains pre-signed URLs can only upload to the specific keys encoded in those URLs. The cost of an attacker exploiting a pre-signed URL is bounded: at most, they fill one file slot with data, which gets cleaned up by the lifecycle rule.

Session creation is the multiplication point. Each new session can generate up to 200 pre-signed URLs. Rate-limiting session creation at the source — before any pre-signed URLs are issued — is the most efficient place to apply the control.

We also add a per-session cap of 200 pre-signed URLs as a secondary control to prevent a single session from generating unlimited URLs even if session creation rate limiting is somehow bypassed.

---

## 15. No draft resumption in V1

**Decision:** Customers cannot resume an in-progress RFQ after page refresh, tab close, or from a different device. The only recovery paths in V1 are: (a) keep the tab open and retry failed network calls, or (b) submit successfully and receive a receipt number.

**Why it seems wrong:** B2B customers may spend 20 minutes on an RFQ and lose work to a misclick.

**Why we chose this:** Resume requires JWT re-bind, shareable links, or customer accounts — meaningful complexity for the first release. Files are tied to a session prefix in S3; resume without re-upload implies copying or re-authorizing objects across sessions.

Warm lead capture (Step 1) and server-side autosave still provide value for sales follow-up and operations recovery without exposing a partial-resume UX that fails at submit time.

---

## 16. Autosave does not block the user

**Decision:** Autosave failures are surfaced as a subtle indicator but do not block form navigation or prevent the user from continuing to fill out the form.

**Why it seems wrong:** If autosave fails, the user's data isn't saved. They could lose work. Shouldn't we block them and force them to acknowledge the failure?

**Why we chose this:** Autosave is a background convenience, not a submission. The submission flow has its own explicit durability guarantee (the receipt). Blocking the user on an autosave failure creates the second-worst outcome we're trying to avoid: the user is interrupted and frustrated by an infrastructure problem that doesn't actually prevent them from submitting.

The correct moment to surface infrastructure failures is at submit time, not during form entry. If the user can't autosave, the session state is still alive in the browser. If they submit successfully, nothing is lost. If they then close the tab before submitting, they lose the metadata they entered after the last successful autosave — but the warm lead record (contact info) is already written, and the uploaded files are already in S3.

The "Draft not saved" indicator is present so that a technically aware user can see there's a problem and either wait, retry, or copy their metadata somewhere safe. It does not require interaction.

---

## 17. Re-submission of the same session is idempotent

**Decision:** If a customer submits, receives a network error, and retries, the second submission of the same `session_id` returns the same receipt number without creating a duplicate record.

**Why it seems wrong:** The client got an error. From the client's perspective, the submission failed. Why would we act like it already exists?

**Why we chose this:** Network errors on the client do not mean the server failed. The most common pattern: the WordPress plugin successfully wrote the receipt (steps 5–8 in the submit sequence), but the HTTP response was lost due to a network timeout before reaching the client. The client sees an error; the server has a completed receipt.

If we treated a retry as a new submission, we'd create a duplicate quote in the ERP. Two engineers would work the same RFQ, or the customer would get two quote responses.

The idempotency check is simple: at the start of the submit handler, check whether the session's `rfq_sessions` row already has `status = 'submitted'`. If it does, return the existing `receipt_number`. The client gets the correct receipt number, the success screen renders, and no duplicate is created.

This pattern (detect-and-return on retry rather than re-execute) is safe here because the receipt write is the final, atomic step. Once `status = 'submitted'` is in the DB and `receipt.json` is in S3, the submission is complete and re-running the sequence would be redundant and harmful.

---

## 18. Part-scoped file uploads in Step 2

**Decision:** Files are uploaded within part rows in Step 2, not from a shared pool. Each row has a client-generated `part_id`. Drawings uploaded in a row belong to that part in the manifest. Step 3 collects metadata for the same rows; it does not reassign files.

**Why it seems wrong:** A flat upload zone with assignment in Step 3 feels simpler — upload everything first, organize later.

**Why we chose this:** The manifest nests `drawing_file_keys` under each part. Part-scoped upload keeps the UI, client state, and manifest aligned from the start. A flat pool requires an extra assignment step and creates orphan drawings if the customer skips it. Submit validation stays straightforward: each manifest part entry is self-contained.

**Trade-off accepted:** Extra S3 objects may accumulate if the customer removes a part row or replaces a file after upload. Keys not referenced in the final manifest are ignored at submit. The ERP import worker copies only manifest-referenced objects to quote storage, then deletes the entire intake prefix — including orphans — after quote creation succeeds.

---

## 19. Manifest is the file source of truth; upload-only S3 for customers

**Decision:** The final manifest lists exactly which S3 objects belong to the RFQ. The customer may leave extra objects in the session prefix (removed rows, replaced files). WordPress validates only manifest keys at submit. The ERP copies only manifest keys to canonical quote storage, then deletes the entire intake prefix after successful import. The plugin never gives customers pre-signed DELETE URLs.

**Why it seems wrong:** Letting orphans accumulate feels messy. Letting customers "remove" files without deleting them is confusing. Cleaning at upload time would keep the bucket tidy.

**Why we chose this:** Delete capability in the browser — even scoped pre-signed DELETE — expands the attack surface. Upload-only is simpler and safer: the customer can only add bytes, never remove evidence or disrupt another session's objects. Orphan cleanup at ERP quote creation is a trusted, server-side step with a clear trigger (quote record written). The manifest is already the contract between intake and ERP; extra keys are irrelevant noise until import deletes the whole prefix.

**Trade-off accepted:** Intake prefixes may contain more objects than the manifest between submit and import. Storage cost during that window is acceptable.

---

## 20. Plugin secrets encrypted at rest, write-only in admin

**Decision:** S3 secret key, ERP webhook secret, and JWT signing secret are encrypted before storage in `wp_options`. The admin UI uses password fields that never display the current value. Operators can rotate by entering a new value; they cannot retrieve the existing secret after save.

**Why it seems wrong:** WordPress options are already behind the admin login. Encryption adds complexity. Plaintext would be simpler to debug.

**Why we chose this:** A database backup, SQL injection, or compromised admin session should not yield usable third-party credentials in plaintext. Encoding (base64) is not encryption. Write-only UI prevents shoulder-surfing in admin and makes "copy existing key" impossible — which is correct; secrets should be sourced from the credential provider (Supabase, ERP) at rotation time, not read back from WordPress.

**Trade-off accepted:** Plugin must ship an encryption key on activation. Loss of that key brick encrypted secrets (operators re-enter from source). Acceptable — same as any encrypted-at-rest system.

---

## 21. Private S3 bucket — no public object access

**Decision:** The intake bucket is private. Anonymous users cannot list or read objects. Upload and download occur only via pre-signed URLs or server credentials.

**Why it seems wrong:** Public buckets with unguessable UUID keys are a common pattern and seem simpler for debugging.

**Why we chose this:** CAD files and customer contact metadata are sensitive. UUID keys leak through manifests, logs, and referrer headers. Bucket-level privacy is the baseline; pre-signed URLs add scoped, time-limited access for uploads.

---

## 22. TypeScript and Tailwind for the React form

**Decision:** The customer-facing RFQ form is implemented in **TypeScript** (React, `.tsx` files) and styled exclusively with **Tailwind CSS**. Vite compiles source to a JS + CSS bundle enqueued by WordPress. No plain JavaScript source files and no ad-hoc CSS stylesheets for form UI.

**Why it seems wrong:** WordPress plugins are traditionally PHP with a small jQuery script. TypeScript and Tailwind add a Node build step and a frontend toolchain separate from the PHP plugin.

**Why we chose TypeScript:** The manifest and REST contracts are strict and nested (contact, per-part metadata, global fields, file keys). Type errors at build time are cheaper than failed submits or malformed payloads. Multi-step form state is easier to refactor safely with shared types (e.g. `RfqManifest`, API error shapes).

**Why we chose Tailwind:** The form must look polished and consistent regardless of the active WordPress theme. Utility-first CSS keeps styling co-located with components, ships as one compiled CSS file with the bundle, and avoids theme CSS leaking into or fighting with custom stylesheets. Operators do not edit form styles in WP admin — the bundle is the source of truth.

**Trade-off accepted:** Developers must run `npm run build` (or CI) before deploying the plugin. Styling changes require a rebuild, not a quick edit in the WP theme customizer.

---

## 23. Production automated testing before release

**Decision:** V1 ships with layered automated tests and **progressive CI** tied to [IMPLEMENTATION](IMPLEMENTATION.md) milestones M1–M5. S3 access uses an injectable `S3ClientInterface`. PR CI uses a mock S3 adapter; an **M2 validation spike** and pre-release/main E2E runs prove Supabase-compatible presigned PUT, CORS, and Content-Type behavior — not assumed from POST-policy documentation. Runtime baseline: PHP 8.3+ (matches production host), PHPUnit 11.

**Why it seems wrong:** WordPress plugins often rely on manual QA before release. A validation spike plus Supabase or LocalStack E2E adds Docker, wp-env, and Node tooling before the product exists.

**Why we chose this:** [PRD §7](PRD.md) failure-mode guarantees (receipt durability, idempotent submit, health-check fallback) regress silently without automation. Pre-signed PUT URLs do not support POST-style policy documents (see [TESTING.md §5](TESTING.md)); Supabase behavior must be validated empirically during M2, not inferred from PRD policy wording. The architecture already separates testable units (ADD §5 server-side keys, §6 HeadObject before receipt, §12 JWT in memory). Mock S3 keeps PR feedback fast; real endpoint smoke on main/nightly catches provider-specific quirks.

**Trade-offs accepted:** Contributors need Docker (wp-env), Composer, and Node. ERP import tests live in the ERP repo (ADD §7, §8). Manual staging smoke supplements automation before production deploy but is not a merge gate.

See [Planning/TESTING.md](TESTING.md) §3–§5 for the acceptance-criterion registry, progressive gates, and S3 validation requirements.
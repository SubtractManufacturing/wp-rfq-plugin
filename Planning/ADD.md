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
8. [ERP polls for new submissions — no webhooks from WordPress](#8-erp-polls-for-new-submissions--no-webhooks-from-wordpress)
9. [Receipt written to both S3 and WordPress DB](#9-receipt-written-to-both-s3-and-wordpress-db)
10. [Manifests without receipts are treated as incomplete by the ERP](#10-manifests-without-receipts-are-treated-as-incomplete-by-the-erp)
11. [Airtable as a warm fallback, not a backup system](#11-airtable-as-a-warm-fallback-not-a-backup-system)
12. [JWT stored in React memory, not localStorage](#12-jwt-stored-in-react-memory-not-localstorage)
13. [Draft resumption via sessionStorage, not a full session replay API](#13-draft-resumption-via-sessionstorage-not-a-full-session-replay-api)
14. [Rate limiting session creation, not uploads](#14-rate-limiting-session-creation-not-uploads)
15. [No cross-device draft resumption in V1](#15-no-cross-device-draft-resumption-in-v1)
16. [Autosave does not block the user](#16-autosave-does-not-block-the-user)
17. [Re-submission of the same session is idempotent](#17-re-submission-of-the-same-session-is-idempotent)

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

We still capture the customer's contact information (name, email, company) as the first required step — this is our warm lead. We don't need an account to have a durable record of who they are.

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

## 8. ERP polls for new submissions — no webhooks from WordPress

**Decision:** The ERP import worker runs on a cron schedule and queries for new receipts. WordPress does not push notifications to the ERP.

**Why it seems wrong:** Webhooks are lower-latency. Polling adds up to 5 minutes of delay between receipt and ERP import. That feels slow.

**Why we chose this:** Webhooks from WordPress to the ERP create a coupling in exactly the direction we're trying to avoid: WordPress (the slow-changing public layer) would need to know the ERP's endpoint URL, authenticate to it, and handle the case where the ERP is mid-deployment and not responding.

If the ERP is down when a webhook fires, WordPress would need retry logic, dead-letter queuing, and backoff — essentially reimplementing a message queue in WordPress PHP. That's complexity without proportionate benefit.

The 5-minute import lag is acceptable for the business. Quotes don't need to appear in the ERP within seconds of submission — engineers don't start reviewing them immediately. A 5-minute SLA on import is fast enough.

Polling is simpler, more resilient, and easier to reason about: the import worker wakes up, queries for work, does work, goes back to sleep. If the ERP was down, it just processes the backlog on the next wake cycle.

---

## 9. Receipt written to both S3 and WordPress DB

**Decision:** The receipt is written to S3 (`receipt.json`) first, then indexed in the WordPress MySQL database (`rfq_sessions` row with `status = 'submitted'`).

**Why it seems wrong:** Writing to two places introduces inconsistency risk. What if the S3 write succeeds and the DB write fails? Now the data is split across two systems.

**Why we chose this:** The two stores serve different purposes and the inconsistency case is handled explicitly.

S3 is durable storage. `receipt.json` in S3 is the authoritative record. It never changes after it's written.

The WP DB row is a queryable index. It allows the ERP import worker to query `WHERE status = 'submitted'` rather than listing S3 objects (which is slower, costs money per LIST call, and is harder to paginate). It also allows operations staff to quickly look up a receipt by number.

The inconsistency case — S3 write succeeds, DB write fails — is handled by the ERP import worker's idempotency logic. If the worker finds a `receipt.json` in S3 with no corresponding WP DB row (which it discovers by querying WP), it creates the WP row and proceeds. The S3 record is always the recovery path.

We write S3 first (not DB first) precisely because S3 is the durable store. If DB write succeeds and S3 write fails, we have a phantom receipt row pointing to files we can't confirm are there. If S3 write succeeds and DB write fails, we have real files and a real receipt that we just need to index.

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

## 12. JWT stored in React memory, not localStorage

**Decision:** The session JWT is stored as React state. It is never written to `localStorage` or `sessionStorage`.

**Why it seems wrong:** `localStorage` would persist the JWT across page refreshes, making session resumption easier without any additional server round-trips.

**Why we chose this:** JWTs stored in `localStorage` are readable by any JavaScript running on the page, including injected scripts from compromised third-party dependencies (a common XSS vector on WordPress sites, which frequently run many third-party plugins and scripts). A stolen JWT lets an attacker upload files to the victim's session prefix or hijack their in-progress submission.

In-memory storage means the JWT is only readable by React's own component tree. An XSS attack can still target the current page session, but cannot exfiltrate the JWT for use in a different session or browser.

The trade-off accepted: if the user refreshes the page, the JWT is lost and a new session starts. We mitigate this with `sessionStorage`-based draft persistence (see Decision 13), which preserves the form data but not the JWT. A page refresh causes a new session to be created, and the draft is re-hydrated from `sessionStorage`. The old session's uploaded files remain in S3 under the old session prefix; those files are not automatically recovered to the new session. This is an accepted limitation for V1.

---

## 13. Draft resumption via sessionStorage, not a full session replay API

**Decision:** Draft state (form metadata, not file blobs) is stored in `sessionStorage` to allow recovery from accidental page refreshes within the same browser tab. There is no server-side session replay endpoint that returns a full draft to a new browser context.

**Why it seems wrong:** A proper "save and resume" feature would let customers close the tab, come back tomorrow, and pick up where they left off. `sessionStorage` only survives within the same tab.

**Why we chose this:** Full cross-device or cross-session resumption requires either a customer account (which we've decided against for V1) or a shareable session URL (which introduces security risks — anyone with the URL could access or complete someone else's draft). Neither is worth the complexity for V1.

The most common accidental loss scenario is a page refresh or a browser crash. `sessionStorage` handles both of these cases within the same browser. It is simple to implement and covers the majority of accidental data loss without introducing new security surface area.

`sessionStorage` data is tab-scoped and cleared when the tab is closed, which is actually the behavior we want: the draft is transient, not a permanent record. The permanent record is the warm lead in the WP DB (written after Step 1) and the uploaded files in S3.

---

## 14. Rate limiting session creation, not uploads

**Decision:** Rate limiting is applied at the session creation endpoint (`POST /sessions`) per IP, not at the file upload endpoint.

**Why it seems wrong:** File uploads are where the real resource cost is. Wouldn't it make more sense to rate-limit uploads?

**Why we chose this:** Pre-signed upload URLs expire after 30 minutes and are scoped to a specific S3 key. An attacker who obtains pre-signed URLs can only upload to the specific keys encoded in those URLs. The cost of an attacker exploiting a pre-signed URL is bounded: at most, they fill one file slot with data, which gets cleaned up by the lifecycle rule.

Session creation is the multiplication point. Each new session can generate up to 200 pre-signed URLs. Rate-limiting session creation at the source — before any pre-signed URLs are issued — is the most efficient place to apply the control.

We also add a per-session cap of 200 pre-signed URLs as a secondary control to prevent a single session from generating unlimited URLs even if session creation rate limiting is somehow bypassed.

---

## 15. No cross-device draft resumption in V1

**Decision:** The system does not support resuming an in-progress RFQ from a different device or browser. V1 only supports in-tab recovery via `sessionStorage`.

**Why it seems wrong:** B2B customers may start an RFQ on their work laptop and want to finish on a different machine, or hand it off to a colleague.

**Why we chose this:** Cross-device resumption requires one of: (a) customer accounts, (b) a shareable link tied to the session, or (c) email-based magic links. All three add meaningful complexity to the first version of the system.

The upload step is the hard part of an RFQ. Files don't transfer between sessions: if a customer needs to resume on a different device, they'd have to re-upload all their CAD files anyway, since the files are in S3 under the original session prefix. The metadata (form fields) could theoretically be recovered, but without the files, the resume is only partial.

This trade-off should be revisited after V1 if warm leads data shows significant abandonment patterns suggesting cross-device usage.

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
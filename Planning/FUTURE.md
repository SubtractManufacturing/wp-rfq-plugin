# Future Ideas

Backlog of possible enhancements **not in current scope**. Do not reference this file from `PRD.md` or `ADD.md`. When an item ships, move it into the PRD and remove it here.

See `.agents/planning.md` for documentation conventions.

---

## Phone number required on Step 1

Make phone a required contact field on the Step 1 contact record and manifest.

## Job title form field

Enable collection of `job_title` in Step 1 UI. Schema and API already accept the field.

## Job title form field

Enable collection of `job_title` in Step 1 UI. Schema and API already accept the field.

## Formal NDA workflow in intake form

Generate, send, or collect e-signatures for NDAs when the customer checks "Treat as NDA".

## Email verification (magic links)

Require email verification via magic link before submit or file upload if bot abuse becomes a problem.

## Success screen call-to-action

Button linking to a "learn more about our quoting process" page (URL configurable in WP admin).

## Configurable max parts per RFQ

WP admin setting to override `RFQ_MAX_PARTS` without code change.

## Configurable upload file size limits and enforcement hardening

WP admin settings with **two independent limits** — **part/CAD max size** and **drawing max size** — overridable without redeploy. Shipped defaults: **500 MB parts**, **50 MB drawings** (operators can raise or lower either limit independently as business needs change). Localized React config and all server validation paths must read the effective limits from the same source (not scattered constants).

**Close enforcement gaps** when this ships (V1 code constants and submit-time checks are interim; presigned PUT cannot rely on POST-style `content-length-range`):

- **`POST /upload-urls`:** require client-declared `content_length`; reject before presign if over the effective limit for `file_type`.
- **Presigned PUT:** where Supabase/S3 allows, bind an exact signed `Content-Length` from the declared size so the provider rejects a mismatched body at PUT time (M2 spike findings apply).
- **Submit (authoritative):** `HeadObject` every manifest key; reject submit if `ContentLength` exceeds the effective limit for that file category — no receipt written.
- **React:** block oversized files before requesting an upload URL (UX; not the security boundary).
- **Ops backstop:** optional bucket/prefix max object size aligned with the higher of the two admin limits.

**Audit logging for backoff events:** record structured intake security events when a user is rejected for oversize or related upload abuse — at minimum: timestamp, `session_id`, client IP, `file_type`, declared and/or observed byte size, effective limit, rejection stage (`upload-url`, `put`, `submit`), and outcome. Surface in a WP admin log view or exportable table (not only `error_log`); never log file contents or secrets. Enables ops to spot abuse patterns and tune part vs drawing limits without code changes.

## ERP-synced material catalog

Fetch material suggestions from ERP Postgres instead of plugin JSON + admin overrides.

## International shipping via online form

Collect country and full address fields; support non-US/Canada destinations in the manifest.

## Webhook retry queue in WordPress

Retry failed ERP import webhooks with backoff and dead-letter logging instead of fire-and-forget single POST.

## Session resume

Allow a customer to resume an unfinished intake session within a time window (e.g. 24–72 hours) without re-uploading files. Likely requires a server-side session re-bind flow (re-issue JWT for an existing `session_id`) and a customer-facing entry point (magic link or similar). Distinct from cross-device resume — could start with same-browser, same-device return.

## Cross-device draft resumption

Resume an in-progress RFQ from a different device or browser. Requires accounts, shareable session links, or email magic links. Files are tied to the original session prefix in S3, so resume logic must re-associate or copy objects.

## Customer email on receipt

Send the customer an email with their receipt number when submission succeeds.

## CSV export from WordPress intake list

Export the WordPress intake admin list to CSV for lead follow-up or external reporting.

## Customer RFQ status portal

Authenticated or link-based view of submission and quote status.

## Zip / archive upload

Batch upload with server-side extraction into individual part files.

## AI-assisted metadata extraction

Extract material, tolerance, or quantity hints from uploaded drawings.

## Mutation testing

Post-V1 quality hardening — measure test effectiveness beyond line coverage.

## Visual regression testing

Automated screenshot diffing if marketing polish becomes a release gate.

## Load / performance testing

Session-creation abuse and upload throughput beyond rate-limit unit coverage.

## Required Codecov diff coverage on PRs

Enforce no coverage decrease on touched files (optional PR comments until then).

## WP/PHP compatibility matrix as required PR gate

Run PHP 8.3 + multiple WordPress versions on every PR after nightly matrix is stable (nightly report-only first).

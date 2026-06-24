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

## International phone numbers

Full E.164 collection in UI (country selector, variable length). V1 schema already includes `phone_country_code` for migration path.

## Email verification (magic links)

Require email verification via magic link before submit or file upload if bot abuse becomes a problem.

## Success screen call-to-action

Button linking to a "learn more about our quoting process" page (URL configurable in WP admin).

## Configurable max parts per RFQ

WP admin setting to override `RFQ_MAX_PARTS` without code change.

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

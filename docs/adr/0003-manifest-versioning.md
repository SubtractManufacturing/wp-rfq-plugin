---
status: accepted
---

# Manifest versioning

Every submitted manifest carries `manifest_version` as its **first field**. A manifest without that field is **v1 (legacy)** - that includes everything submitted before versioning existed, and ERP import must keep handling it.

## Versions

- **v1 (legacy, implicit):** No `manifest_version`. Per part: `material` string, `tolerance` is a tier label (`standard`, `precision`, `custom`; later also `economy`/`tight` in pre-v2 builds), `tolerance_detail` required when `tolerance` is `custom`. No `finish`, `finish_color`, `finish_specification`, or `plastic_color` fields.
- **v2 (current):** `manifest_version: 2` first in the manifest. Adds per-part `finish`, `finish_color`, `finish_specification`, and `plastic_color` (all nullable). Per-part `tolerance` is the **actual tolerance string** for ERP import verbatim (e.g. `+/- 0.005"`), never a tier label; `tolerance_detail` is always `null` in v2.

## Rule for future changes

**Any change to the manifest's shape or to the meaning/format of an existing field must bump `manifest_version`.** That means:

1. Increment `MANIFEST_VERSION` in `frontend/src/lib/manifest.ts` (and the `manifest_version` type in `frontend/src/types/manifest.ts`).
2. Add a row to the Versions list above describing the new version and how it differs.
3. Make the WordPress validator accept the new version (and keep accepting every older version) in `RFQ_Manifest_Validator`.
4. Note the new version in `Planning/PRD.md` wherever the manifest is specified, and in the ERP handoff for the same release.

Additive, backward-compatible fields that older importers can safely ignore still count as a version bump if an ERP importer would lose customer data by ignoring them.

## Consequences

- The ERP branches on `manifest_version` (absent = v1) instead of guessing from which fields happen to be present.
- WordPress never rewrites a stored manifest; receipts and manifests in S3 stay exactly as submitted, in whatever version they were submitted.

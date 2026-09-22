---
status: accepted
---

# Public GitHub Releases for manual in-app updates

RFQ Intake sites install Plugin Updates from stable GitHub Releases in the
public `SubtractManufacturing/wp-rfq-plugin` repository. The repository is
public so sites need no shared or per-site GitHub credential. Public access
does not change the project's proprietary, all-rights-reserved licensing.

The plugin bundles Plugin Update Checker and accepts only a Release asset named
`rfq-intake-<version>.zip`. GitHub's generated source archive is never an
update candidate because it has the wrong install layout. Drafts, prereleases,
missing assets, and GitHub failures produce no update offer and do not affect
the installed plugin.

Plugin Updates are manual only. Publishing a stable Plugin Release makes it
visible to every site, but an administrator chooses when to install it.
Automatic updates are disabled for this plugin. Operations installs on staging
before production; there are no environment-specific channels.

## Considered Options

- **Private repository with a token on each site.** Rejected. Fine-grained
  token provisioning, rotation, expiry, and shared-host API failures add an
  operational dependency to every site.
- **Private development repository plus public distribution repository.**
  Rejected. It keeps development history private but requires a second
  repository and cross-repository publication workflow.
- **Private repository with public Releases.** Not available: GitHub Release
  visibility follows repository visibility.
- **WordPress.org or a custom update service.** Rejected. Neither is needed for
  the small set of controlled sites, and both introduce a separate publishing
  surface.
- **Automatic Plugin Updates.** Rejected. A human-controlled staging-first
  install is the release safety gate.

## Release and compatibility policy

- Merging the protected Release Please pull request is the publication
  approval.
- Release assets are immutable and retained indefinitely. A defect is fixed by
  a higher patch release, never by replacing, deleting, or silently rebuilding
  an existing package.
- GitHub HTTPS, immutable Releases, and repository/workflow access controls are
  the package trust boundary. The plugin does not maintain a separate signing
  key or checksum protocol.
- The first updater-enabled package is installed manually.
- A release remains compatible with the immediately previous frontend/API
  contract so an RFQ already open in a browser can finish after the backend is
  updated.
- Database migrations are versioned separately from Plugin Releases,
  idempotent, and additive. Migration failure is recorded, shown to
  administrators, and degrades plugin health to the Airtable fallback.

## Consequences

- Anyone can inspect, download, and technically install the plugin. Proprietary
  terms are a legal restriction, not technical access control.
- Release notes and repository history are public.
- Sites depend on GitHub only for update discovery and package download; GitHub
  downtime never stops the installed plugin from running.
- A bootstrap manual upload is unavoidable for sites installed before the
  updater exists.

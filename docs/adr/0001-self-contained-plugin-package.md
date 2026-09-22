---
status: accepted
---

# Self-contained plugin package

A Plugin Package must install with WordPress's normal plugin upload. The repo layout cannot do that: Composer lives at the repo root, the form bundle is built into a gitignored directory, and a GitHub source archive unpacks to a versioned folder name. The package is therefore a zip named `rfq-intake-<version>.zip` whose only top-level directory is `rfq-intake/`, containing the plugin PHP, the built form, and production Composer packages.

## Considered Options

- **GitHub source archive.** Rejected. It unpacks to a folder named after the repo and tag, so WordPress installs a second copy beside `rfq-intake`.
- **Version in the inner directory.** Rejected. `rfq-intake-0.0.1/` would also install beside the live plugin. The version stays in the zip file name and the `Version:` header.
- **Repo-root Composer path as the install layout.** Rejected. That path exists for local development and wp-env. A site that only has `wp-content/plugins/rfq-intake` would not find it.

## Consequences

- The plugin looks for `vendor/autoload.php` inside its own directory first, and still checks the repo-root and wp-env paths when those are present.
- Development keeps the current repo layout. Only the Plugin Package is self-contained.

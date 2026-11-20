---
status: accepted
---

# Intake Viewer role

The PRD originally kept the Intake Sessions list on standard administrator permissions with no custom capabilities. Operations staff need to see who started or submitted an RFQ without getting WordPress administrator access, so the plugin now ships one role and two capabilities.

## Decision

- `rfq_view_intake_sessions` gates the RFQ Intake menu, the Intake Sessions list and the orphaned-manifest notice.
- `rfq_delete_intake_sessions` gates the list's Delete action.
- A user without a stored RFQ capability gets it exactly when they pass a full `user_can( $user_id, 'manage_options' )` check: a `map_meta_cap` filter runs that check and maps the result to `exist` or `do_not_allow`, so `map_meta_cap` and `user_has_cap` policies on `manage_options` (grants and denials) carry over. Administrator access never depends on stored role data, site policies that restrict `manage_options` also restrict RFQ access, and we never write to the core `administrator` role.
- The `rfq_intake_viewer` role ("RFQ Intake Viewer") stores only `read` and `rfq_view_intake_sessions`. Plugin Settings stay on `manage_options`.
- A viewer is locked to the list. Every other wp-admin screen redirects there (on `admin_init`, and on `admin_page_access_denied` for screens core rejects before `admin_init`). Login lands there too. Dashboard and Profile are removed from the menu, and the admin bar drops links to blocked screens. `edit_user` on themselves maps to `do_not_allow`, which blocks the profile screen, REST user updates and application passwords. The `save-user-color-scheme` AJAX action, which core runs without an `edit_user` check, is rejected for viewers.
- The redirect uses `wp_redirect` with our own admin URL. `wp_safe_redirect` would replace it with the Dashboard URL when the admin host differs from the home host, and the Dashboard would redirect again.
- If the viewer role ever loses `rfq_view_intake_sessions`, viewers are sent to the site home instead of the list, so the list can't redirect to itself.
- Lockdown applies only when the viewer role is the user's *only* role and they lack `manage_options`. Giving an editor (or an administrator) the viewer role adds list access without taking away their own role's access.
- The role is installed on activation and, through a `rfq_intake_roles_version` option, on the first request after an update (roles live in the database, and updates do not re-run activation). `uninstall.php` removes the role (on every site, on multisite). Bump `RFQ_Roles::VERSION` when the role's stored capabilities change.

## Scope

Single-site only, like the rest of the plugin (tables, options and secrets are all per site). On multisite, uninstall removes the role from every site, but network-level screens such as the network user profile are not locked down.

## Consequences

- Sites can give staff list-only access without a role-editor plugin.
- Viewers can still reset a forgotten password through `wp-login.php` (account recovery, not profile management).
- After deactivation (before deletion), viewer accounts fall back to plain `read` access: Dashboard and Profile only, because the lockdown code is no longer running.

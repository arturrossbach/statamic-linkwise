# Permissions

Linkwise adds a single Control Panel permission so you can control who has access.

## The permission

**Manage Linkwise** — grants access to the Linkwise section of the Control Panel
and all its tools (suggestions, auto-linking, broken links, URL changer, domains,
custom keywords, activity log).

A role without this permission won't see the **Linkwise** item in the navigation
at all. Super admins have it automatically.

## Granting it

1. Go to **Users → Roles** and edit (or create) a role.
2. Under permissions, enable **Manage Linkwise**.
3. Assign that role to the users who should manage internal linking.

## Notes & limits

- It's a single, all-or-nothing permission — there are no finer-grained
  per-tool permissions in this version.
- Linkwise also respects Statamic's own entry and collection permissions: a user
  can only act on content they're otherwise allowed to edit.

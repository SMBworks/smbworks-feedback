# SMBworks Feedback

Drop-in WordPress plugin for collecting page feedback from clients and stakeholders during a build. A floating **💬 Feedback** button opens a short form; items land in a **Feedback** inbox in wp-admin with threaded replies, @mentions and open / resolved / archived states. Works with any theme. No build step: plain JS, using `wp.apiFetch` and `wp.i18n` in the admin.

Started from the Shopify feedback snippet (Sept 2026): same form, fields and look, but it saves to the site's own database instead of a webhook.

## Install

Copy or symlink this folder into `wp-content/plugins/` and activate it. Activation creates the tables and gives Administrators and Editors the `smbfb_manage_feedback` capability.

```sh
ln -s ~/Repositories/smbworks-feedback /path/to/site/wp-content/plugins/smbworks-feedback
```

Then go to **Feedback → Settings** (needs `manage_options`):

- **Show the widget to:** everyone including guests (the default), logged-in users, reviewers only, or nobody.
- **Button label** and **position** (bottom right or bottom left).
- **New feedback goes to:** comma-separated addresses. Leave it empty to email only the people who are @mentioned.
- **Replies:** email the person who left the feedback when someone replies. On by default; guests get the reply text and the page link.
- **Uninstall:** whether deleting the plugin also drops its tables and settings. Off by default.

## Who can do what

| | Guest | Logged-in user | Reviewer (`smbfb_manage_feedback`) |
| --- | --- | --- | --- |
| Leave feedback | yes, enters name and email (remembered in `localStorage`) | yes, name and email filled from the account, read-only | yes |
| @mention autocomplete in the widget | no (typed mentions still match) | no | yes |
| Can be @mentioned | no | no | yes |
| Inbox: read, reply, resolve, archive | no | no | yes |

To make a client a reviewer, give their role the capability, e.g. `wp cap add author smbfb_manage_feedback`, or add roles with the `smbfb_reviewer_roles` filter before activation.

**Mentions** match a reviewer's username or nicename. They also match a unique first name or display name with spaces removed, so `@Mark` works the way the original widget's placeholder suggested. If two reviewers share a first name, neither is notified, so use the handle that autocomplete inserts (`@nicename`). Each person gets at most one email per comment or reply, and the email links straight to the thread (`admin.php?page=smbworks-feedback#item=12&reply=34`).

## Spam and safety

- **Guests:** a honeypot field, a 2-second minimum fill time, and 10 submissions per IP per 10 minutes (`smbfb_guest_rate_limit`). The IP is stored only as a salted hash.
- **Mention probing:** guests never see who a mention matched, and the user-search endpoint is for reviewers only.
- **URL check:** feedback must point at the site's own host (`smbfb_allowed_hosts` to add more).
- **Signed-in requests:** these use the core REST cookie nonce. Guest requests send none, so page caching doesn't break the form.
- **Text handling:** text is saved with `sanitize_textarea_field` and rendered as text nodes, never as HTML.
- **Privacy:** Tools → Export / Erase Personal Data cover feedback and replies by email address. Erasing anonymises the author, so threads stay readable.

## Data

Three tables, `{prefix}smbfb_items`, `smbfb_replies` and `smbfb_mentions`, are created with `dbDelta`. The schema version is kept in the `smbfb_db_version` option and checked on `plugins_loaded`. That way updates copied over the folder, and sites on a network activation, upgrade without reactivating.

**items:** status (`open` / `resolved` / `archived`), type, comment, full page URL plus a normalised `page_key` (path only, used for grouping and filtering), page title, post ID, author (user ID or guest name and email), IP hash, context JSON (viewport, screen, pixel ratio, scroll, user agent, language, colour scheme), reply count, who closed it and when, and created / updated / last-activity times (UTC).

**replies:** item ID, parent ID (threads), author, body, time.

**mentions:** item ID, reply ID (0 means the original comment), user ID, created and notified times. A unique key on (item, reply, user) prevents duplicate emails.

To change the schema, edit `smbfb_schema_sql()`, bump `SMBFB_DB_VERSION`, and put anything `dbDelta` can't do (renames, data moves) in `smbfb_migrations()` under the new version number.

## REST API: `smbworks-feedback/v1`

| Route | Who | What |
| --- | --- | --- |
| `POST /items` | whoever the widget is shown to | submit feedback |
| `GET /items` | reviewers | list; `status`, `page_key`, `type`, `mentioned`, `search`, `page`, `per_page`; includes status counts and filter options |
| `GET /items/{id}` | reviewers | item and replies |
| `POST /items/{id}` | reviewers | `status`: open, resolved or archived |
| `POST /items/{id}/replies` | reviewers | `body`, optional `parent_id` |
| `GET /users?search=` | reviewers | reviewers for @mention autocomplete |

## Hooks

**Filters**

| Filter | Use |
| --- | --- |
| `smbfb_types` | Feedback types: key => label |
| `smbfb_widget_allowed` | Bool: show the widget to the current visitor |
| `smbfb_reviewer_roles` | Roles given the capability on install |
| `smbfb_allowed_hosts` | Hosts feedback URLs may use |
| `smbfb_guest_rate_limit` | Guest submissions per 10 minutes |
| `smbfb_mail_headers` | Headers for notification emails |

**Actions:** `smbfb_item_created`, `smbfb_reply_created`, `smbfb_status_changed`.

**Styling:** override the `--smbfb-*` custom properties on `.smbfb`; there's a dark variant for `prefers-color-scheme: dark`.

## Development

```sh
composer global require --dev wp-coding-standards/wpcs:^3 phpcompatibility/phpcompatibility-wp
phpcs            # uses phpcs.xml.dist
```

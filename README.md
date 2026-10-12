# LFW Change Notifications

A WordPress plugin that emails the site administrator, or anyone you list, when content is published, updated, unpublished or scheduled.

Each email says what happened, what changed (title, content type, links to view, edit and compare revisions), who changed it and when, in the site's time zone.

Works on its own: no LFW account, no outside service. Mail goes out through `wp_mail()`, so it uses whatever mail setup the site already has. The plugin sends nothing anywhere else and collects no usage data.

Requires WordPress 6.4 or later and PHP 7.4 or later. Tested up to WordPress 6.8.

## Settings

Settings > Change Notifications (administrators only):

- **Recipients:** up to 20 addresses. Empty means the site admin email.
- **Content types:** posts, pages and any public custom post type.
- **Events:** published, updated after publishing, unpublished (including private, trash and permanent deletion), scheduled.
- **Delivery:** right away, hourly digest or daily digest.
- **Imports:** optionally skip changes made with WP-CLI or the WordPress Importer.

The screen also shows how many changes are waiting, when the next send is due, and has a "Send a test email" button.

## How batching works

- **Right away:** everything changed in one request goes out in one email at the end of that request. A bulk edit of 200 posts, or a WP-CLI import of 200 posts, is one email. Each email lists 50 items and counts the rest.
- **Repeated saves:** a save of an item that was emailed in the last 10 minutes waits until those 10 minutes are up. Every save in that time goes out as one follow-up with a save count.
- **Burst limit:** at most 5 right-away emails in 10 minutes. Past that, changes wait and go out together.
- **Digest:** one email per hour or per day, each changed item listed once, such as "Published, then updated: Fall league (Saves: 3)".

Autosaves, revisions, auto-drafts, drafts, pending items and media uploads are left out.

## Abuse controls

Abuse case: a stranger cannot make the site send mail, because only content changes by signed-in users, WP-CLI or WordPress's own scheduled publishing reach the queue; and a bulk edit cannot flood an inbox, because changes are batched, collapsed per item and capped.

1. **Only administrators configure it.** The settings screen, the save and the test email all check `manage_options`, and every form has a nonce.
2. **Nothing a visitor submits triggers mail.** Comments, form entries and posts created in a request with no signed-in user are ignored.
3. **Recipients are validated.** Each address must pass `is_email()` unchanged; the list is capped at 20. Rejected text is counted in a notice, never shown back.
4. **Flood protection.** One email per request, one queue entry per item, 5 right-away emails per 10 minutes, 50 items listed per email, 500 items held in the queue (past that, changes are counted only), and a test email at most once a minute.
5. **No header or markup injection.** Titles are turned into plain text, one line, 200 characters at most.
6. **Least data.** Stored: the settings, plus a short queue of pending notifications (post ID, user ID, event, time; the title only for an item deleted permanently). Entries are removed as their email is sent. A failed send is tried once more and then dropped.
7. **No cost.** Nothing metered; no outside calls.

## Development

```sh
./test.sh          # throwaway WordPress 6.8 + WP-CLI in Docker; tears down after
KEEP=1 ./test.sh   # leave it running on http://127.0.0.1:8097 (admin / admin)
```

The test mounts `tests/mu-test-harness.php` as an mu-plugin. It captures mail with a `pre_wp_mail` filter (nothing is sent) and moves the plugin's clock forward through the `lfwcn_now` filter so the 10-minute window can pass in a test.

Coding standards: `phpcs` with the WordPress and PHPCompatibilityWP rulesets (`phpcs.xml.dist`).

### Filters

- `lfwcn_collapse_window` (default 600): seconds within which saves of one item are combined.
- `lfwcn_burst_limit` (default 5): right-away emails allowed per window.
- `lfwcn_max_listed` (default 50): items listed in one email.
- `lfwcn_skip_change`: return true to leave a change out, false to let it in.
- `lfwcn_email`: the `to`, `subject`, `message` and `headers` before `wp_mail()`.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

Made by [LFW.com](https://lfw.com/).

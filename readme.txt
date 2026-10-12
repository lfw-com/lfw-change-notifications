=== LFW Change Notifications ===
Contributors: lfwcom
Tags: notifications, email, editorial, publishing, digest
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Email the site administrator, or anyone you list, when content is published, updated, unpublished or scheduled.

== Description ==

When a post, a page or any public custom post type changes, the people you list get an email that says:

* what happened: published, updated, unpublished, made private, moved to trash, deleted or scheduled
* what changed: the title and content type, with links to view it, edit it and compare revisions (when WordPress has an earlier revision to compare with)
* who changed it, by name and username
* when, in the site's time zone

Choose the recipients (the site admin email by default), the content types, the events, and the delivery: right away, an hourly digest or a daily digest.

Works on its own: no LFW account, no outside service. Mail goes out through wp_mail(), so it uses whatever mail setup the site already has, such as an SMTP plugin or the host's mail. The plugin sends nothing anywhere else and collects no usage data.

= One email per batch =

* A bulk edit, an import or any other run that changes 200 items sends one email listing them. An email lists 50 items and counts the rest ("And 150 more changes").
* Repeated saves of the same item within 10 minutes of its last email are combined into one follow-up email with a save count.
* Right-away delivery sends at most 5 emails in any 10 minutes. Past that, changes wait and go out together.
* A digest sends one email per hour or per day, listing each changed item once.

= What is left out =

* Autosaves, revisions and auto-drafts.
* Drafts and pending items until they are published.
* Media uploads.
* The block editor's follow-up save of classic meta boxes, which repeats the save just reported.
* Optionally, changes made with WP-CLI or the WordPress Importer (Settings > Change Notifications > Imports).

== Abuse controls ==

Abuse case: a stranger cannot make the site send mail, because only content changes by signed-in users, WP-CLI or WordPress's own scheduled publishing reach the queue; and a bulk edit cannot flood an inbox, because changes are batched, collapsed per item and capped.

* Only administrators (the manage_options capability) can open the settings screen, save it or send a test email. Every form has a nonce.
* Nothing a visitor submits triggers mail. Comments, form entries and posts created in a request with no signed-in user (by a front-end submission plugin, for example) are ignored.
* Recipients are checked with is_email(). Up to 20 addresses are kept; anything else is removed and never shown back on the screen.
* Every setting is rebuilt from a fixed list of choices when it is saved.
* Flood limits: one email per request, one entry per item while it waits, 5 right-away emails per 10 minutes, 50 items listed per email, 500 items held in the queue (past that, changes are counted only).
* Titles are turned into plain text before they go into an email, so a title cannot add mail headers or markup.
* The only data stored is the settings and a short queue of pending notifications: post ID, user ID, event and time (and the title of an item deleted permanently, since the post is gone). Each entry is removed once its email is sent. If wp_mail() reports a failure, the entry is tried once more and then dropped.

== Installation ==

1. Upload the lfw-change-notifications folder to /wp-content/plugins/, or install it from a zip under Plugins > Add New.
2. Activate it.
3. Open Settings > Change Notifications. Add recipients, choose content types, events and delivery, and save.
4. Use "Send a test email" to check that the site can send mail.

== Frequently Asked Questions ==

= Emails do not arrive. =

Use "Send a test email" on the settings screen. If WordPress reports a failure, or the message never arrives, the site's mail setup needs attention. Many hosts block or filter mail sent with PHP's mail function; an SMTP plugin connected to a real mail service usually fixes it.

= Digests and follow-ups are late. =

They run on WP-Cron, which runs when someone visits the site. On a quiet site, or one with WP-Cron turned off (DISABLE_WP_CRON), set up a server cron job that runs WordPress's scheduled tasks every few minutes.

= When does the daily digest go out? =

24 hours after delivery was set to "Daily digest", and every 24 hours after that.

= Can a developer change the limits? =

Yes, with filters: lfwcn_collapse_window (seconds, default 600), lfwcn_burst_limit (default 5), lfwcn_max_listed (default 50), lfwcn_skip_change (leave out or let in a change) and lfwcn_email (the message before it goes to wp_mail()).

= What happens on deactivation and uninstall? =

Deactivation stops scheduled sends and drops pending notifications. Settings are kept. Deleting the plugin removes its settings, queue and scheduled events.

== Changelog ==

= 1.0.0 =
* First release.

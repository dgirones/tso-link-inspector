=== TSO Link Inspector ===
Contributors: deadko
Donate link: https://ko-fi.com/deadko_cat
Tags: broken links, link checker, seo, maintenance, links
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.5.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find and fix broken links across your entire WordPress site without opening each post.

== Description ==

**TSO Link Inspector** scans all published posts, pages and custom post types for links, then checks each one via HTTP to detect broken links, redirects, insecure HTTP URLs and connection errors. All results are displayed in a dashboard where you can fix links directly without opening the editor.

= Key Features =

* **Scans** posts, pages, and any custom post type.
* **Detects** HTTP errors: 404, 410, 500, DNS failures, SSL errors, timeouts, and redirects.
* **Edit URLs inline** for post content from the admin panel, or **Go to edit** for comments, widgets, menus, and terms in their native WordPress screens.
* **Smart URL Suggester**: automatically tests HTTPS upgrade, follows redirect chains, and tries www/non-www variants.
* **Unlink**: removes the `<a>` tag but keeps the visible text.
* **Bulk actions**: re-check, unlink, mark as OK, or delete multiple links at once.
* **Export CSV**: export any filtered view to a spreadsheet.
* **Per-article view**: click any article to see all its links in one place.
* **Posts with issues**: summary of articles that contain broken, redirected, or unchecked links.
* **Internal / External** scope tabs to separate same-site and outbound links.
* **Quality filters**: empty anchor text, generic anchors (“click here”), and links to unpublished posts.
* **View post at link**: open the front end with the matching link highlighted (post content, plain-text URLs, and comments).
* **Plain-text URLs** in post content are listed separately (not treated as hyperlinks) with **Go to edit** to open the post.
* **Convert to /path**: optional row and bulk action to replace same-site absolute URLs with site-relative paths.
* **Dashboard widget** with broken/unchecked counts and shortcuts.
* **Export CSV and PDF** for any filtered view.
* **Settings Help tab** with full documentation.
* **Configurable automatic checks**: recheck intervals for OK and broken links, plus hourly batch size.
* **HTTP insecure detection**: flags active links still using HTTP instead of HTTPS.
* **Ignore list**: add domains or URL prefixes to never scan or check.
* **Continue check / Restart from zero**: resume a stopped run, or wipe progress and recheck everything.
* **History**: Settings tab listing recent URL changes from Edit, Suggest, Convert to /path, and HTTPS (capped log; oldest rows pruned automatically).
* **Save when unverified**: Edit link / Suggest can still save a URL if this server cannot confirm it (geo-block, bot wall, timeout), with an option to ignore that domain.
* **Scan images and iframes**: optionally detect broken `<img src>` and embedded videos.
* **Scan user comments**: optionally check links in approved comments.
* **Custom fields (ACF)**: optionally scan URL fields added by plugins like Advanced Custom Fields.
* **Daily automatic scan** and **hourly batch check** via WP-Cron. Can close the browser while checking.
* **Email alerts** for fully broken links (no redirect): send one summary after automated checks, or a periodic digest (7 / 15 / 30 days), with an optional notification address.
* **Nofollow broken links**: automatically adds `rel="nofollow"` to broken links so search engines ignore them.
* **Preserve post dates**: editing a link does not update the post modification date.
* Compatible with LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache, SG Optimizer, Breeze, and Cloudflare.
* **Extended scanning**: plain-text URLs in posts, Gutenberg block JSON, navigation menus, responsive media (srcset/picture), page-builder `data-*` attributes, widget sidebars, taxonomy descriptions, Site Editor templates/reusable blocks, and plain URLs in custom fields.
* **Third-party sources**: register extra link collectors with `tsoliin_register_link_source()`.
* **Optional WooCommerce scanning**: external product URLs, downloadable files, featured/gallery images, plus a Products with issues view.
* Includes Catalan and Spanish translations.

= How it works =

1. Click **Scan now** to extract all links from your posts.
2. Click **Check now** to send HTTP requests to every URL (runs server-side, you can close the browser). If you stop mid-run, use **Continue check** to resume, or **Restart from zero** to wipe progress and recheck everything.
3. Review results using the **Broken**, **Redirect**, **HTTP insecure** and other filter tabs.
4. Fix links using **Edit URL**, **Suggestion**, **Unlink** or **Mark as OK** from each row. Recent URL changes appear under **Settings → History**.

= Redirect intelligence =

The plugin follows the full redirect chain manually so it captures the real final destination, not just the last HTTP code. It automatically ignores trivial redirects (trailing slashes, CDN tokens, WP attachment pages, login walls) to avoid false positives.

== Installation ==

1. Upload the `tso-link-inspector` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin from the **Plugins** menu in WordPress.
3. Go to **Tools > TSO Link Inspector**.
4. Click **Scan now** to run the first scan, then **Check now** to verify all links.

== Frequently Asked Questions ==

= When does the automatic scan run? =
A full scan runs once daily. The link check runs hourly in small batches. Both use WP-Cron. The next scheduled runs are shown at the top of the dashboard.

= Can I fix links without opening each post? =
Yes. Use **Edit URL** to replace a URL, **Unlink** to remove the anchor tag while keeping the text, or **Suggestion** to automatically find a working alternative.

= Is it compatible with Gutenberg? =
Yes. The plugin processes `post_content` using WordPress standard filters and works with the Block Editor, Classic Editor, and most page builders.

= Will it scan custom fields (ACF)? =
Yes. Enable **Custom fields (ACF / Meta)** in Settings. The plugin scans URL, HTML and text fields, plus ACF fields stored as IDs (image, file, gallery, page link, relationship) and ACF Options pages. You can add keys to the exclusion list to skip specific fields.

= What does the HTTP Insecure filter show? =
Links that are working (not broken) but still use `http://` instead of `https://`. Use the Suggestion button to upgrade them with one click.

= What is the Ignore List? =
A list of domains or URL prefixes (one per line) that will never be scanned or checked. Useful for domains that block bots (Amazon, Facebook) or your own site.

= Does it change the post modification date? =
Only if you want it to. Enable **Do not update modification date** in Settings to edit links without changing `post_modified`.

= What does "Mark as OK" do? =
It sets the link status to 200 OK manually without making an HTTP request. The post is not modified. Useful for URLs that are temporarily blocked but you know they work.

= What is Continue check vs Restart from zero? =
If a check was stopped and unchecked links remain, **Continue check** resumes from where it left off (already-checked links stay as they are). **Restart from zero** clears check progress and rechecks every link from scratch. **Continue check** starts immediately; **Restart from zero** asks for confirmation.

= Where is the URL change History? =
Open **Tools → TSO Link Inspector → Settings → History**. It lists recent changes from Edit link, Suggest apply, Convert to /path, and Upgrade to HTTPS (including bulk actions). The log keeps a limited number of rows (oldest removed automatically). You can delete all history records; this does not change posts or the link list.

= Can I save a URL when the server cannot verify it? =
Yes. In Edit link or Suggest, if this server gets a geo-block, bot wall, or timeout, you can still save the URL. Optionally tick **ignore domain** so that host is added to the Ignore list and skipped in future scans/checks.

== External services ==

This plugin does not send data to servers operated by the plugin author. HTTP and DNS run from your WordPress server only after an administrator clicks **Scan now**, **Check now**, **Suggestion**, **Upgrade to HTTPS**, or when scheduled checks are enabled in Settings.

= HTTP checks of URLs stored on your site =

When a check runs, the plugin sends HTTP HEAD and, if needed, HTTP GET requests to each stored `http://` or `https://` URL. Data sent: the destination URL, a browser-like User-Agent, and standard `Accept` / `Accept-Language` headers. Responses are stored only in your WordPress database (status code, redirect URL, redirect chain).

Those destinations are websites already linked from your content, not a service chosen by the plugin. Terms of use and privacy policy: those of each destination site.

= DNS lookups =

Before requesting a hostname, the plugin may resolve A/AAAA records on the server (`dns_get_record` / `gethostbynamel`) so private, loopback, or reserved addresses are not contacted. This is a DNS lookup from your server; post content and user accounts are not sent.

== Screenshots ==

1. Main dashboard with statistics and link list.
2. Filter tabs: All, Broken, Redirect, OK, HTTP insecure, Manual locks, Not checked.

== Changelog ==

= 2.5.1 =
* Fix: the admin froze (and could hit "Maximum execution time exceeded") while a scan or check was running, because every admin page load ran a batch inline.
* Fix: high CPU and memory on shared hosting: cron, open tabs and the keep-alive all worked non-stop; now a single worker runs at a time and rests between batches.
* Fix: a scan or check could never finish when one post or link crashed PHP or threw an error, or when a stored link was rescanned in a loop.
* Improvement: the check that starts after a manual scan now only checks new links instead of rechecking every link from scratch, so the dashboard counts no longer drop to zero; "Restart check" still forces a full recheck.
* Improvement: the scan progress bar no longer sits at 99% while comments, menus, terms, templates and widgets are scanned, and it names the source being scanned and moves while a long source is in progress.
* Fix: the scan progress text flickered between two different wordings, and the new source names were missing from the Spanish and Catalan translations.

= 2.5.0 =
* Fix: the link list ran one extra database query per comment-type row to check if it could be edited/viewed (get_comment() was not cached across the two places that call it), showing up as hundreds of duplicate queries on sites with many comment links; the comment cache is now primed once per page load like it already was for posts.
* Fix: on posts with many links, the list re-queried the same post's content from the database for every row that belonged to it (up to 3 times per row across the title, view, and edit links), instead of reusing the post already fetched for an earlier row; a request-level cache now keeps this to one lookup per post per page load.
* Fix: the "Edit post" and "View post" links for each row asked WordPress for the post by ID, which re-queries the database when that post isn't already in WordPress's own cache; they now reuse the post already fetched for that row, removing another source of repeated queries.
* Fix: some of the plugin's own background scan/check progress options could still be marked to autoload in the database from older versions, so WordPress reloaded its entire options table on every scan/check "tick" that updated them; these are now normalized to not autoload on upgrade, matching what the code already requests.

= 2.4.9 =
* Fix: link list column headers (e.g. Last checked) could drift out of alignment with their own column depending on how long that site's URLs/titles were; column widths are now fixed instead of recalculated from content.
* Improvement: Last checked now always shows dd/mm/yyyy 24h time, instead of following each site's own date/time format setting.
* Fix: disabling WooCommerce product scanning no longer leaves "Product" permanently and silently checked in the Content types list; products are only scanned as generic content while WooCommerce scanning is enabled.
* Fix: the "Last checked" column header could wrap onto two lines, pushing its sort arrow below the text instead of beside it like the other columns; the column is now wide enough for the label to stay on one line.
* Improvement: added a Type filter dropdown (Link, Image, Iframe, Comment, Menu, etc.) to the link list, independent of the Status/Quality/Internal-External filters and the dashboard stat cards.
* Fix: enabling "Media (attachment)" under Content types scanned almost no media items, because WordPress stores attachments with post_status "inherit" (not "publish"); the scan now also accepts "inherit" for attachments.
* Fix: an absolute server filesystem path stored in another plugin's postmeta (e.g. a backup-file path starting with "/home/.../public_html/...") could be mistaken for a site-relative URL and checked as a bogus link at the domain root (always reporting 404 even though the real file exists); such paths are now recognized and skipped.

= 2.4.8 =
* Fix: Mobile and web view in night mode
* Fix: Unresolved template placeholders (e.g. `${sec.image}`, `{{state.logo}}`) are no longer scanned as broken links; a rescan clears any already-stored placeholder rows.

See changelog.txt in the plugin folder for older versions
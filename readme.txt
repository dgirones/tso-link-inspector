=== TSO Link Inspector ===
Contributors: deadko
Donate link: https://ko-fi.com/deadko_cat
Tags: broken links, link checker, seo, maintenance, links
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.5.4
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
* **Paced background work**: one batch at a time with a pause between batches, so scans stay usable on shared hosting.
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

= Why does the progress bar stay on the same number for a while? =
It is pausing, not stuck. Only one batch runs at a time and each batch is followed by a pause of a similar length, so the plugin never uses more than about half of one PHP process. Posts take the first 70% of the bar; comments, menus, terms, templates and widgets share the rest, and the bar names the source it is working on. A source with many items can hold the same number for minutes. You can close the browser: the work continues on the server.

= Does the plugin slow down my site? =
Scanning and checking only run in the WordPress admin and via WP-Cron, never on pages your visitors see. While a scan runs, one PHP process is busy about half of the time. If your host is very limited, lower the hourly batch size in Settings.

= What happens right after a scan? =
The links the scan just found are checked automatically. Links checked earlier keep their status, so the dashboard counts stay meaningful during the run. Use Restart from zero when you want every link tested again.

= What is Continue check vs Restart from zero? =
If a check was stopped and unchecked links remain, **Continue check** resumes from where it left off (already-checked links stay as they are). **Restart from zero** clears check progress and rechecks every link from scratch. **Continue check** starts immediately; **Restart from zero** asks for confirmation. The check that starts automatically after a scan behaves like **Continue check**.

= Where is the URL change History? =
Open **Tools → TSO Link Inspector → Settings → History**. It lists recent changes from Edit link, Suggest apply, Convert to /path, and Upgrade to HTTPS (including bulk actions). The log keeps a limited number of rows (oldest removed automatically). You can delete all history records; this does not change posts or the link list.

= Can I save a URL when the server cannot verify it? =
Yes. In Edit link or Suggest, if this server gets a geo-block, bot wall, or timeout, you can still save the URL. Optionally tick **ignore domain** so that host is added to the Ignore list and skipped in future scans/checks.

== External services ==

This plugin does not send data to servers operated by the plugin author. HTTP and DNS run from your WordPress server only after an administrator clicks **Scan now**, **Check now**, **Suggestion**, **Upgrade to HTTPS**, or when scheduled checks are enabled in Settings.

= HTTP checks of URLs stored on your site =

When a check runs, the plugin sends HTTP HEAD and, if needed, HTTP GET requests to each stored `http://` or `https://` URL. Data sent: the destination URL, a browser-like User-Agent, and standard `Accept` / `Accept-Language` headers. Responses are stored only in your WordPress database (status code, redirect URL, redirect chain).

Those destinations are websites already linked from your content, not a service chosen by the plugin. Terms of use and privacy policy: those of each destination site.

= Optional DNS second opinion (Cloudflare) =

Disabled by default. When an administrator enables "DNS second opinion" in Settings and this server cannot resolve a domain, the plugin asks Cloudflare public DNS (DNS-over-HTTPS, `https://cloudflare-dns.com/dns-query`) whether that domain resolves to an address (A or AAAA record), before reporting "Domain does not exist". Data sent: only the hostname of the link being checked (no page content, no site data). The answer is cached for 6 hours. Service: Cloudflare, Inc. Terms: https://www.cloudflare.com/website-terms/ — Privacy policy: https://www.cloudflare.com/privacypolicy/

= DNS lookups =

Before requesting a hostname, the plugin may resolve A/AAAA records on the server (`dns_get_record` / `gethostbynamel`) so private, loopback, or reserved addresses are not contacted. This is a DNS lookup from your server; post content and user accounts are not sent.

== Screenshots ==

1. Main dashboard with statistics and link list.
2. Filter tabs: All, Broken, Redirect, OK, HTTP insecure, Manual locks, Not checked.

== Changelog ==

= 2.5.4 =
* Fix: Smart Suggest no longer offers the www / non-www alias of a link that already works, and never offers a domain-for-sale or parking page (HugeDomains, Sedo, Dan, Afternic…) as the fix for a broken link.
* Fix: Smart Suggest and the Edit link confirmation could fail with a generic "upstream request failed" error on slow or unreachable hosts, because the many checks outlasted the web server's gateway limit; they now have an 8-second budget for starting new checks and shorter per-request waits, so they finish well under 30 seconds.
* Fix: Edit link on an http:// link asked "This server cannot confirm the URL… Save it anyway?" even when the new https:// URL worked (200); it only matched the single URL the plugin itself suggested. The URL you type is now checked directly, and the confirmation appears only when this server really cannot verify it. It is also faster, because the extra suggestion checks are skipped.
* Fix: cookies are now kept between redirect hops, like a browser; sites with a silent login/consent redirect chain (e.g. developer.android.com) are no longer reported as a redirect loop. 999 is labelled "Access blocked (bot?)".
* Fix: a redirect loop (more than 8 redirects) was shown as a normal redirect; it is now reported as broken "Too many redirects (loop)".
* Fix: when a server answers HEAD with 405 or 501, the GET answer is now used; LinkedIn's anti-bot code 999 is treated as "blocked by bot protection", not as broken.
* Fix: links with app/protocol schemes (whatsapp:, tg:, geo:, callto:, market:, webcal:, magnet:, file:…) were resolved as relative paths and reported as 404; they are now skipped like mailto: and tel:.
* Fix: bare URLs in comments and plain text lost their closing bracket (Wikipedia-style .../Foo_(bar)) and kept trailing quotes, ellipsis or non-breaking spaces, so working links were reported as broken; the end of the URL is now cut correctly.
* Fix: internationalized domains (e.g. español.es, 日本語.jp) were reported as "Domain does not exist"; they are now converted to punycode before the DNS and HTTP checks.
* Fix: a redirect to a domain this server cannot resolve skipped the DNS second opinion; it now gets the same verification.
* Fix: servers that answer HEAD with 404, 500 or 501 but serve the page to GET were reported as broken; any 4xx/5xx to HEAD is now re-checked with GET.
* Improvement: when Cloudflare DNS second opinion confirms that a domain this server cannot resolve exists, the link is shown as "Domain not resolved by this server (200 OK by Cloudflare)" and counted under OK; Recheck applies the same verification. Cloudflare's answer only counts as OK when the domain has an A or AAAA record; a domain with no address records, or whose name servers fail, is reported as "Domain does not exist (DNS)". Catalan and Spanish translations added.
* Improvement: when this server cannot resolve a domain, the link is now shown as "Domain not resolved by this server (DNS, unconfirmed)" and is not counted as broken; "Domain does not exist" is reported only when confirmed. New optional setting "DNS second opinion" confirms with Cloudflare public DNS (off by default, hostname only).
* Fix: a working domain could be reported as "Domain does not exist (DNS)" after a single failed or temporary DNS lookup; the check now queries A and AAAA separately, retries, lets the real HTTP request decide, and only reports a DNS failure once it is confirmed.
* Fix: a plain-text URL split by inline formatting tags (e.g. http://www.<strong>Youtube</strong>.com/...) was reported as a broken link "http://www"; the URL is now read as visitors see it, and incomplete bare "www" hosts are ignored.

= 2.5.3 =
* Fix: the coming-soon detection no longer requests a made-up /tsoliin-nx-…/ URL, so it stops adding 404 entries to 404-monitor and redirect logs.

= 2.5.2 =
* Fix: the Link Inspector screen could keep showing the WordPress maintenance page (until a hard refresh) if it reloaded itself while WordPress was installing updates; it now waits for the updates to finish and never caches that page under its URL.
* Improvement: background scans/checks pause while WordPress installs updates.

= 2.5.1 =
* Fix: the admin froze (and could hit "Maximum execution time exceeded") while a scan or check was running, because every admin page load ran a batch inline.
* Fix: high CPU and memory on shared hosting: cron, open tabs and the keep-alive all worked non-stop; now a single worker runs at a time and rests between batches.
* Fix: a scan or check could never finish when one post or link crashed PHP or threw an error, or when a stored link was rescanned in a loop.
* Improvement: faster plugin screens, a progress bar that names the source being scanned, and the check after a scan now only tests new links instead of rechecking everything.

See changelog.txt in the plugin folder for older versions
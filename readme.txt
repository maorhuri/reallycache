=== ReallyCache ===
Contributors: mvn
Requires at least: 5.9
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.7.5
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Tags: cache, performance, speed, page cache

Really fast, really simple page caching. Zero configuration, safe by default, server-aware.

== Description ==

ReallyCache serves your pages as static HTML instead of rebuilding them with PHP and MySQL on every visit. Activate it and it works — no setup screens, no fifty toggles.

**What makes it different:**

* **Zero configuration** — smart defaults, WooCommerce protected automatically, logged-in users and carts always bypass the cache.
* **Smart purging** — when a post changes, exactly the affected pages are refreshed: the post, the home page, its categories, its author archive. Comments, term edits, menu and theme changes are covered too.
* **Server-aware architecture** — a universal file cache today; LiteSpeed server cache and Nginx FastCGI cache drivers are on the roadmap and will be selected automatically where available.
* **Safe by default** — no HTML rewriting, no CSS/JS combining, nothing that breaks page builders or checkout flows.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/reallycache/` or install via the WordPress admin.
2. Activate it. That's it — caching is on.

== Frequently Asked Questions ==

= Does it work with WooCommerce? =

Yes, out of the box. Cart, checkout and account pages are never cached, visitors with items in their cart bypass the cache entirely, and product pages are purged automatically when stock or prices change.

= How do I temporarily disable caching while working on the site? =

Use Development Mode on the ReallyCache screen — it pauses caching for an hour or a day and turns itself back on.

== Changelog ==

= 0.6.0 =
* Security hardening across the board, following a full internal audit: Redis values are now HMAC-signed (a neighbor on shared Redis can no longer plant or forge entries) with an unguessable per-site key prefix; arbitrary Host headers and non-default ports are refused as cache keys; tracking parameters are stripped from the request before WordPress renders (cache-poisoning guard); responses that set cookies or declare Cache-Control private/no-store are never cached; security headers (CSP, HSTS, X-Frame-Options…) are captured at store time and replayed on cache hits; wp-config.php editing is now atomic and only ever touches a plain boolean literal; WebP conversion validates image content before decoding, runs Imagick with an explicit coder and resource limits, and skips images of non-public content; background workers use atomic locks, follow no redirects and only fetch same-host URLs.
* Font Display Swap (on by default): text renders immediately in a fallback font while webfonts load — inline @font-face, Google Fonts and Elementor custom fonts.
* Warm-up now skips pages that are already fresh in cache, including ones a visitor warmed while the queue was waiting.
* WebP: disk-space estimate (with a free-space check and warning) shown before converting the library.

= 0.5.0 =
* Redis insights: live hit rate, queries served from RAM, key count, Redis memory usage and a breakdown of what lives inside by cache group — plus tuning: excluded groups and a default expiry cap, all applied through our drop-in (v2).
* Admin-bar menu: a ReallyCache parent with Clear page cache, Warm cache, Flush Redis cache and Clear everything.
* LCP Boost (on by default): the hero image loses lazy-loading, gains fetchpriority=high and a preload hint at cache time — attribute-only, safe with any builder.
* "Convert on upload" toggle for WebP.
* Server recommendations popup on Nginx: copy-paste blocks for browser-cache headers and compression.

= 0.4.0 =
* Fixed: cache warm-up no longer stalls. The queue now runs on a real background worker (loopback + inline pump from status polling + cron watchdog) instead of relying on WP-Cron timing.
* Warm-up progress bar: live progress with the URL currently being warmed.
* WebP conversion: images are converted locally (Imagick/GD — they never leave the server). New uploads convert automatically; one click converts the existing library through a throttled queue, and cached HTML serves the .webp twins. Live stats show images converted and space saved.
* Browser cache: static assets get year-long immutable expiry headers (automatic .htaccess on Apache; ready-made Nginx snippet in docs/).
* Redis object cache: when Redis is detected on the server, one toggle installs our object-cache drop-in — per-site key namespacing, fail-open design, no extra plugin needed.

= 0.3.0 =
* Warm Cache: one click queues the whole site (home, posts, pages, products, archives) and warms it through a throttled background queue — a few pages at a time, never overloading the server.
* Smart cache: when content changes, exactly the affected URLs are purged and immediately re-warmed in the background, so the next visitor still gets a hot page.
* WooCommerce protection toggle (on by default): cart, checkout and account pages are detected from WooCommerce settings — any slug, any language — and excluded before WordPress even loads.
* New "Permanent" cache lifetime: pages live until a purge removes them.

= 0.2.0 =
* New admin interface: single-screen app with live status tiles, one-click cache clearing, development mode and instant feedback. No page reloads — everything runs over the REST API.
* Full Hebrew translation and RTL support.

= 0.1.0 =
* Initial release: file-based page cache with smart targeted purging, WooCommerce safety, development mode, and automatic garbage collection.

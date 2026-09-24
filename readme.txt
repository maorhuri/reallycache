=== ReallyCache ===
Contributors: mvn
Requires at least: 5.9
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.4.0
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

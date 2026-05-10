=== AutoPurge ===
Contributors: scottdayman
Tags: cloudflare, cache, purge, cdn, performance
Requires at least: 5.5
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Auto-purges Cloudflare cache when WordPress content changes. Cache-Tag based, no Worker required, single-API-call related-content invalidation.

== Description ==

AutoPurge is a WordPress plugin that keeps a Cloudflare-cached site fresh by automatically purging only what changed when content is edited. It is designed as a drop-in replacement for Cloudflare's official APO WordPress plugin.

= How it works =

1. **Emits a `Cache-Tag` HTTP response header** on every cacheable page. Each tag identifies the entity behind the response (e.g. `post-42`, `term-category-7`, `home`, `feed`). Cloudflare records these tags against the cached object.
2. **Purges by tag, URL, and/or prefix** when content changes. Tag-based purging means a single API call can invalidate the post permalink, every paginated archive that contains it, the home page, the feed, the author archive, and the date archives.

The `Cache-Tag` response header is honored on **all Cloudflare plan tiers**. No Worker, Snippet, or Cache Response Rule is required.

= Features =

* Token-only setup. Paste a Cloudflare API token in the admin UI and AutoPurge auto-detects the zone for you. No `wp-config.php` changes.
* Smart edit detection: body-only edits get a narrow purge; significant changes (status, title, slug, terms, author, date, featured image) get a wide purge.
* Captures **old** taxonomy terms — when a post moves between categories, both the old and new term archives are purged.
* Comment-driven purges (narrow purge of the post on approved comments).
* Theme switch, customizer save, and core/plugin/theme update purges.
* Manual purge dashboard: Everything, URLs, Tags, Prefixes.
* Filter hooks for related-posts widgets, custom tag schemas, and per-post skip logic.
* Debounced shutdown flush — multiple rapid saves coalesce into a single batch of API calls.

= What gets purged on a content change =

A *wide* tag purge clears the post itself, the home page (incl. all pagination), the relevant feeds, the post type archive, every term archive the post belongs to (incl. pagination), the author archive, and the year/month/day archives — typically with a single API call.

= Tag schema =

* `html` — any HTML page (used for theme/core update bulk-invalidation)
* `feed` — any RSS/Atom/RDF feed
* `home` — front page or posts page (covers `/`, `/page/2/`, ...)
* `post-{ID}` — singular post / page / CPT
* `post_type-{type}` — post type archive (covers all pagination)
* `term-{taxonomy}-{term_id}` — term archive (covers all pagination)
* `author-{user_id}` — author archive (covers all pagination)
* `date-{Y}` / `date-{Y}-{M}` / `date-{Y}-{M}-{D}` — date archives
* `attachment-{ID}` — attachment

== Installation ==

1. Upload the `autopurge` folder to `/wp-content/plugins/`, or install via **Plugins → Add New → Upload Plugin** and choose the `.zip`.
2. Activate **AutoPurge** from the Plugins page.
3. In Cloudflare, create an API token at <https://dash.cloudflare.com/profile/api-tokens> with two permissions on the target zone:
   * **Zone → Zone → Read** (used once, to detect the zone ID)
   * **Zone → Cache Purge → Purge**
4. In WordPress, go to **Tools → AutoPurge Cache** and paste the token. Click **Save & Verify**. The plugin will verify the token and auto-detect the zone for the site's host.
5. Create two Cloudflare Cache Rules in this order:
   * **Cache HTML for logged-out users** — match: `(http.host eq "example.com" and not starts_with(http.request.uri.path, "/wp-login") and not http.cookie contains "wp-" and not http.cookie contains "wordpress")`. Eligible for cache. Edge TTL 1 hour, browser TTL 2 hours.
   * **Cache static assets** — match: `(http.request.uri.path.extension in {"avif" "css" "gif" "gz" "ico" "jpg" "jpeg" "js" "png" "svg" "ttf" "txt" "webp" "woff" "woff2" "xml"})`. Eligible for cache. Edge TTL 1 year, browser TTL 1 month.
6. Click **Purge Entire Cache** once on the AutoPurge page so subsequently-served HTML carries the `Cache-Tag` header.

== Frequently Asked Questions ==

= Does this require a paid Cloudflare plan? =

No. The `Cache-Tag` response header and purge-by-tag are honored on Free, Pro, Business, and Enterprise plans.

= Does this require a Cloudflare Worker? =

No. The plugin emits the `Cache-Tag` response header directly from PHP via WordPress's `template_redirect` action.

= What if I have a related-posts widget or "popular posts" list? =

Use the `autopurge_related_post_ids` filter to provide IDs of posts that should be purged when a given post changes, or enable **Wide mode** in settings to fall back to `purge_everything` on every change.

= Does this support WordPress multisite? =

Not currently. Single-site only.

= What about Cloudflare's free-plan rate limit? =

Cloudflare allows 5 purge requests per minute on the Free plan. AutoPurge debounces and batches all purges from a single page-save into ≤3 requests, so normal editing flow stays well under the limit.

== Filter hooks ==

`autopurge_response_tags`, `autopurge_post_tags`, `autopurge_post_urls`, `autopurge_skip_post`, `autopurge_change_is_significant`, `autopurge_related_post_ids`. See the README on GitHub for examples.

== Changelog ==

= 2.0.0 =
* Token-only setup with auto zone detection — no `wp-config.php` changes required.
* Rewrote auto-purge to use Cloudflare cache tags (single API call invalidates a post + all related archives across all pagination depths).
* Emits `Cache-Tag` response header with WP-aware schema (`post-{ID}`, `term-{tax}-{id}`, `author-{id}`, `date-{Y-M-D}`, etc.).
* Smart edit detection: body-only edits get a narrow purge; significant changes (status, title, slug, terms, author, date, featured image) get a wide purge.
* Captures **old** taxonomy terms via `set_object_terms`, so moving a post between categories purges both the old and new term archives.
* Replaced `save_post` with `transition_post_status` + `post_updated` for accurate detection of publish/unpublish events.
* Added comment-driven purges (narrow purge of the post on approved comments).
* Added theme switch and customizer save hooks.
* Added Settings page (Tools → AutoPurge Cache) with toggles for auto-purge, edit-detection mode, comment purges, wide mode, and tag prefix.
* Added manual purge by Prefix.
* Added filter hooks: `autopurge_response_tags`, `autopurge_post_tags`, `autopurge_post_urls`, `autopurge_skip_post`, `autopurge_change_is_significant`, `autopurge_related_post_ids`.
* Renamed all internal functions from `puc_*` to `autopurge_*`.
* Removed `cachetags.js` Worker — the `Cache-Tag` response header is honored on all Cloudflare plan tiers.
* Bumped purge batch size from 30 to 100 (the current Cloudflare API limit for tags and URLs).

= 1.4.0 =
URL-based auto-purge with paginated archive URLs. Required `cachetags.js` Worker for tag-based manual purges.

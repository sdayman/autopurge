# <img src="favicon.png" alt="Project Icon" width="24"> AutoPurge

WordPress plugin that automatically purges Cloudflare cache when content changes. Designed as a replacement for Cloudflare's APO WordPress plugin.

## How it works

AutoPurge does two things:

1. **Emits a `Cache-Tag` HTTP response header** on every cacheable WordPress page. Each tag identifies the entity behind the response (e.g. `post-42`, `term-category-7`, `home`, `feed`). Cloudflare records these tags against the cached object.
2. **Purges by tag, URL, and/or prefix** when content changes. Tag-based purging means a single API call can invalidate the post permalink, every paginated archive that contains it, the home page, the feed, the author archive, and the date archives.

Cache-Tag is honored on all Cloudflare plan tiers. No Worker, Snippet, or Cache Response Rule is required.

## Comparison vs. the official Cloudflare plugin

[Cloudflare's own WordPress plugin](https://github.com/cloudflare/Cloudflare-WordPress) is the alternative. AutoPurge is built around purge-by-tag and the `Cache-Tag` response header, which the official plugin does not use. The trade-offs:

### Where AutoPurge is better

| Capability | AutoPurge | Cloudflare plugin |
|---|---|---|
| **Purge granularity** | One API call invalidates a post **and** every paginated archive that contains it (home, term archives, author, date, feed) via `Cache-Tag` | Sends a list of explicit URLs; if archives have N pages, that's N URLs (and pagination depth is enumerated up to a hardcoded limit) |
| **Pagination coverage** | Complete — `home`, `term-{tax}-{id}`, `author-{id}`, `post_type-{type}` tags cover every `/page/N/` automatically | Generates `/page/2/`, `/page/3/`, ... up to a fixed cap; deep archives leak |
| **Old-term handling** | Captures previous taxonomy terms via `set_object_terms` so moving a post between categories purges **both** old and new term archives | Purges only the new terms |
| **Smart edit detection** | Body-only edits get a narrow purge (post + home); significant edits (status, title, slug, terms, author, date, featured image) get a wide purge | Always sends the same URL set on any save |
| **Comment purges** | Narrow purge (post tag + home) on approved comments | No native comment purges |
| **Theme / customizer / plugin / core updates** | `html` tag purge in one API call invalidates every cached HTML response on the site, leaving static assets alone | `purge_everything` (clears static assets too — they have to re-download) |
| **Debounced batching** | All purges from one request coalesce in `shutdown` — typical save = 1–3 API calls | Each hook fires its own API call; bulk edits can hit rate limits |
| **Manual purge by tag/prefix** | Yes, with the same WP-aware tag schema | URLs only (Tags/Prefixes are Enterprise-only in the official UI) |
| **Setup** | Paste a token in the admin; zone is auto-detected | Authenticate with the full Cloudflare account; pick a zone from a dropdown |
| **Footprint** | Single PHP file, ~800 lines | Multi-package Composer dependency tree, vendor directory, ~MB of code |
| **Filter hooks** | `autopurge_response_tags`, `autopurge_post_tags`, `autopurge_post_urls`, `autopurge_skip_post`, `autopurge_change_is_significant`, `autopurge_related_post_ids` | Limited |

### Where the Cloudflare plugin is better

| Capability | Cloudflare plugin | AutoPurge |
|---|---|---|
| **HTML caching out of the box** | Toggles a server-side `cf-edge-cache` header that Cloudflare's edge honors automatically — no Cache Rule required | Requires one Cache Rule to make HTML eligible for cache (one-time, copy-paste from the README) |
| **APO integration** | Native — exposes the APO toggle in the admin UI, integrates with mobile / device-aware caching | None — AutoPurge replaces APO rather than integrating with it |
| **Zone-level Cloudflare settings in WP admin** | Exposes Always Online, Dev Mode, security level, image optimization toggles inside WordPress | Out of scope; manage these in the Cloudflare dashboard |
| **Analytics in WP admin** | Shows Cloudflare analytics inside WP admin | None |
| **Multisite** | Supported | Single-site only |
| **Translations** | i18n / many locales | English strings only |
| **Maintained by Cloudflare** | Official; tracks Cloudflare API changes | Third-party |
| **Test suite** | PHPUnit coverage in repo | None |

### TL;DR

If your priority is **purge accuracy with minimum Cloudflare API calls** and you don't need multisite or APO mobile-aware caching, AutoPurge is the better fit. If you need APO, multisite, or Cloudflare's zone-level toggles inside WP admin, use the official plugin.

## Setup

No `wp-config.php` edits required. The plugin is configured entirely from the WordPress admin.

### 1. Install the plugin

Either:

- **Upload zip:** in WordPress, **Plugins → Add New → Upload Plugin** and choose `autopurge.zip`, or
- **Manual:** drop the `autopurge` folder into `/wp-content/plugins/`.

Activate **AutoPurge** from the Plugins page.

### 2. Create a Cloudflare API token

Go to <https://dash.cloudflare.com/profile/api-tokens> and create a token with these two permissions on the target zone:

- **Zone → Zone → Read** (used once, to detect the zone ID)
- **Zone → Cache Purge → Purge**

### 3. Save the token

In WordPress, go to **Tools → AutoPurge Cache**, paste the token into the **Cloudflare Setup** section, and click **Save & Verify**. The plugin verifies the token and auto-detects the zone ID for the site's host.

The token is stored in the `autopurge_credentials` option (non-autoload). To rotate it, paste a new token and click **Save & Verify**. To remove it, click **Clear Credentials**.

For debug logging to `wp-content/debug.log` (optional):

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

> **Backward compatibility:** if `CF_API_TOKEN` and `CF_ZONE_ID` are defined as constants in `wp-config.php`, they are used in preference to stored credentials. New installs do not need them.

### 4. Cloudflare Cache Rules

Create two Cache Rules in the order shown:

1. Cache HTML for logged-out users:

   ```
   (http.host eq "example.com"
     and not starts_with(http.request.uri.path, "/wp-login")
     and not http.cookie contains "wp-"
     and not http.cookie contains "wordpress")
   ```

   Eligible for cache. Edge TTL: 1 hour. Browser TTL: 2 hours.

2. Cache static assets:

   ```
   (http.request.uri.path.extension in {"avif" "css" "gif" "gz" "ico" "jpg"
     "jpeg" "js" "png" "svg" "ttf" "txt" "webp" "woff" "woff2" "xml"})
   ```

   Eligible for cache. Edge TTL: 1 year. Browser TTL: 1 month.

### 5. First purge

After activating, run **Tools → AutoPurge Cache → Purge Entire Cache** once so subsequently-served HTML is tagged.

## Auto-purge behavior

When a post changes, the plugin queues purge operations and flushes them once at the end of the request (debounced — multiple rapid saves coalesce into a single batch).

| Event | Purge |
|---|---|
| Publish a draft | Wide tag purge for that post |
| Unpublish / trash / delete | Wide tag purge for that post |
| Edit body of published post | Narrow purge: `post-{ID}`, `home`, permalink, home URL |
| Edit title / slug / excerpt / terms / featured image / author / date | Wide tag purge |
| Move post between categories or tags | Wide tag purge including **old** terms |
| Approved comment posted | Narrow purge of the post |
| Theme switch / customizer save / plugin / theme / core update | `html` tag purge |

A wide tag purge clears the post itself, the home page (incl. all pagination), the relevant feeds, the post type archive, every term archive the post belongs to (incl. pagination), the author archive, and the year/month/day archives — typically with a single API call.

## Tag schema

| Tag | Applied to | Notes |
|---|---|---|
| `html` | Any HTML page | Bulk-invalidate all HTML on theme/core update |
| `feed` | Any RSS/Atom/RDF feed | |
| `home` | Front page or posts page | Covers `/`, `/page/2/`, ... |
| `post-{ID}` | Singular post / page / CPT | |
| `post_type-{type}` | Post type archive | Covers all pagination |
| `term-{taxonomy}-{term_id}` | Term archive | Covers all pagination |
| `author-{user_id}` | Author archive | Covers all pagination |
| `date-{Y}` / `date-{Y}-{M}` / `date-{Y}-{M}-{D}` | Date archives | |
| `attachment-{ID}` | Attachment | |

Tags are normalized: lowercase, printable ASCII only, no commas (used as the header separator), no spaces.

## Settings (Tools → AutoPurge Cache)

| Setting | Default | Effect |
|---|---|---|
| Auto-purge | on | Master toggle for automatic purging |
| Edit detection | smart | Smart vs. always-wide on edits to published posts |
| Comment purges | on | Narrow-purge a post when a comment is approved |
| Wide mode | off | Use `purge_everything` on every change (for sites with widespread related-content widgets) |
| Tag prefix | (empty) | Prepend a string to every tag, e.g. `wp-`, to namespace within a shared zone |

## Manual purge dashboard

The Tools → AutoPurge Cache page also offers manual purges:

- **Purge Everything** — clears the entire zone cache
- **Purge Specific URLs** — one absolute URL per line
- **Purge by Cache Tag** — one tag per line
- **Purge by Prefix** — one URL prefix per line (max 30 per request)

## Filter hooks (for site-specific extensibility)

```php
// Add or remove tags emitted on the response header for the current request.
apply_filters( 'autopurge_response_tags', array $tags ) : array

// Modify the tags purged for a post.
apply_filters( 'autopurge_post_tags', array $tags, WP_Post $post, bool $wide ) : array

// Modify the URLs purged for a post.
apply_filters( 'autopurge_post_urls', array $urls, WP_Post $post, bool $wide ) : array

// Skip auto-purging for a specific post (return true to skip).
apply_filters( 'autopurge_skip_post', false, WP_Post $post ) : bool

// Force a body-only edit to be treated as significant (wide purge).
apply_filters( 'autopurge_change_is_significant', false, WP_Post $after, WP_Post $before ) : bool

// Provide IDs of posts that should be purged when a given post changes.
// Use this for sites with related-posts widgets, "popular posts" lists, etc.
apply_filters( 'autopurge_related_post_ids', array(), WP_Post $post ) : array
```

Example: invalidate the 5 most recent posts when any post is edited (covers a "recent posts" widget):

```php
add_filter( 'autopurge_related_post_ids', function( $ids, $post ) {
    $recent = get_posts( [
        'numberposts' => 5,
        'post_status' => 'publish',
        'fields'      => 'ids',
    ] );
    return array_merge( $ids, $recent );
}, 10, 2 );
```

## Limitations

- **Related-posts widgets**: if your theme or a plugin shows post X on the page for post Y, AutoPurge has no way to know that without help. Use the `autopurge_related_post_ids` filter, or enable **Wide mode** to fall back to `purge_everything`.
- **Multisite**: not currently supported (single-site only).
- **Free plan rate limits**: Cloudflare allows 5 purge requests/minute on Free. Bursty editing can hit this; the debounced shutdown flush keeps each post save to ≤3 requests, so normal use is fine.

## Cloudflare API limits

| Operation | Max ops / request |
|---|---|
| Files (URLs) | 100 |
| Tags | 100 |
| Prefixes | 30 |

Batching is automatic — large purges are split into multiple requests.

## Changelog

### 2.0.0
- **Token-only setup** with auto zone detection. Paste a Cloudflare API token in **Tools → AutoPurge Cache** and the plugin verifies the token via `/user/tokens/verify` and resolves the zone ID via `/zones?name=...` by walking the site host's subdomain hierarchy. No `wp-config.php` edits required. (`CF_API_TOKEN` / `CF_ZONE_ID` constants still take precedence if present, for backward compatibility.)
- Rewrote auto-purge to use Cloudflare cache tags (single API call invalidates a post + all related archives across all pagination depths).
- Emits `Cache-Tag` response header with WP-aware schema (`post-{ID}`, `term-{tax}-{id}`, `author-{id}`, `date-{Y-M-D}`, etc.).
- Smart edit detection: body-only edits get a narrow purge; significant changes (status, title, slug, terms, author, date, featured image) get a wide purge.
- Captures **old** taxonomy terms via `set_object_terms`, so moving a post between categories purges both the old and new term archives.
- Replaced `save_post` with `transition_post_status` + `post_updated` for accurate detection of publish/unpublish events.
- Added comment-driven purges (narrow purge of the post on approved comments).
- Added theme switch and customizer save hooks.
- Added Settings page (Tools → AutoPurge Cache) with toggles for auto-purge, edit-detection mode, comment purges, wide mode, and tag prefix.
- Added manual purge by Prefix.
- Added filter hooks: `autopurge_response_tags`, `autopurge_post_tags`, `autopurge_post_urls`, `autopurge_skip_post`, `autopurge_change_is_significant`, `autopurge_related_post_ids`.
- Renamed all internal functions from `puc_*` to `autopurge_*`.
- Removed `cachetags.js` Worker — `Cache-Tag` response header is now honored on all CF plan tiers, no Worker required.
- Bumped purge batch size from 30 to 100 (the current Cloudflare API limit for tags and URLs).
- License changed from GPL-2.0-or-later to GPL-3.0-or-later (alignment with bundled `LICENSE` file).

### 1.4.0 and earlier
URL-based auto-purge with paginated archive URLs. Required `cachetags.js` Worker for tag-based manual purges.

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

AutoPurge is a WordPress plugin that automatically purges Cloudflare cache when content changes. It serves as a pseudo-replacement for Cloudflare's APO WordPress plugin.

## Components

1. **autopurge.php** - WordPress plugin that:
   - Auto-purges related URLs when posts are created/edited/deleted (permalink, home, feeds, archives, taxonomies, author pages, date archives)
   - Provides a manual purge dashboard in wp-admin (Tools > AutoPurge Cache)
   - Purges HTML cache tag on plugin/theme updates

2. **cachetags.js** - Cloudflare Snippet/Worker that assigns cache tags to requests (tagging logic documented in file comments)

## Configuration

The plugin requires two constants in wp-config.php:
- `CF_API_TOKEN` - Cloudflare API token with Zone > Purge permission
- `CF_ZONE_ID` - Cloudflare zone ID from the domain's Overview page

Cloudflare Cache Rules must be configured for the domain (see README.md for rule examples).

Debug logging outputs to wp-content/debug.log when `WP_DEBUG` and `WP_DEBUG_LOG` are enabled.

## Cloudflare API

Uses `api.cloudflare.com/client/v4/zones/{zone_id}/purge_cache` with three purge methods:
- `purge_everything` - Clears entire zone cache
- `files` - Purges specific URLs
- `tags` - Purges by cache tag (requires cachetags.js Worker)

## Code Conventions

- All function names prefixed with `puc_` (Purge URL Cache)
- WordPress hooks used: `save_post`, `wp_trash_post`, `before_delete_post`, `upgrader_process_complete`, `admin_menu`

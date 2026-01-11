# <img src="favicon.png" alt="Project Icon" width="24"> AutoPurge
Pseudo-replacement for Cloudflare APO WordPress plugin

Add the following to your wp-config.php file and replace the PUT_YOUR values:
```
define( 'CF_API_TOKEN', 'PUT_YOUR_TOKEN_HERE' );
define( 'CF_ZONE_ID', 'PUT_YOUR_ZONE_ID_HERE' );
```
Token needs Zone -> Purge -> Purge permission
https://developers.cloudflare.com/fundamentals/api/get-started/create-token/

ZoneID comes from Overview page for your domain
https://developers.cloudflare.com/fundamentals/setup/find-account-and-zone-ids/#copy-your-zone-id

Drop autopurge.php into your /wp-content/plugins directory, and Activate it in your WP-Admin dashboard.

Create two Cache Rules in your Cloudflare account, in this order:
1) `(http.host eq "example.com" and not starts_with(http.request.uri.path, "/wp-login") and not http.cookie contains "wp-" and not http.cookie contains "wordpress")`
 - Set Eligible for Cache, then Edge TTL to 1 Hour (or the duration of your choice). Browser TTL to 2 Hours (or the duration of your choice)
2) `(http.request.uri.path.extension in {"avif" "css" "gif" "gz" "ico" "jpg" "jpeg" "js" "png" "svg" "ttf" "txt" "webp" "woff" "woff2" "xml"})`
 - Set Eligible for Cache, then Edge TTL to 1 Year (or the duration of your choice). Browser TTL to 1 Month (or the duration of your choice)

Upon creating/editing/deleting a post/page, plugin will purge home page, the post/page you're working on, and *most* related pages (category, tag, author, date archives, and paginated archive pages /page/2/ through /page/5/). It will not purge unrelated pages that happen to have a widget that includes links to the post/page you're working on. Also purges all HTML when site updates themes, plugins, or core.

Rapid saves are debounced—URLs are collected and purged once at the end of the request. Large purge requests are automatically batched to respect Cloudflare's API limits (30 URLs or tags per request).

## Dashboard Tool
In wp-admin, there is now an AutoPurge Cache tool to Purge Everything, Prefixes, or Cache Tags (cache tags need the Snippet/Worker described below).

## Snippet/Worker
Use cachetags.js as a Snippet or Worker to add cache tags to your cached resources. Cache Tags consist of file extensions (minus the leading dot), "home" for the front page, "html" for content-type `text/html`, and directories on the website.

Query strings are ignored for consistent tagging (style.css?v=123 gets the same tags as style.css?v=456). Trailing slashes are normalized (/about/ and /about get the same tags). Filenames are lowercased for case-insensitive matching.

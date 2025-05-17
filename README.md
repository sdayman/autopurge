# autopurge
Pseudo-replacement for Cloudflare APO WordPress plugin

Add the following to your wp-config.php file and replace the PUT_YOUR values:
define( 'CF_API_TOKEN', 'PUT_YOUR_TOKEN_HERE' ); 
define( 'CF_ZONE_ID', 'PUT_YOUR_ZONE_ID_HERE' );

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

Upon creating/editing/deleting a post/page, plugin will purge home page, the post/page you're working on, and *most* related pages (category, tag, author, date, etc.). It will not purge unrelated pages that happen to have a widget that includes links to the post/page you're working on.

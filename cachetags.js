/**
 * CacheTags.js – Cloudflare Snippet/Worker
 * Description: Assigns cache tags to requests based on the URL path and extension.
 * Version: 1.2.0
 * Author:  Scott Dayman
 * License: GPL-2.0-or-later
 *
 * Tags assigned:
 * - Extensions like jpg, png → tag with extension + filename (lowercase) + path segments
 * - Homepage   → "html", "home"
 * - Clean URLs → "html" + path segments
 *
 * Query strings are ignored (pathname only).
 * Trailing slashes are normalized (/about/ treated same as /about).
 */

export default {
  async fetch(request) {
    try {
      let { pathname } = new URL(request.url);

      // Normalize trailing slash (except for root)
      if (pathname.length > 1 && pathname.endsWith("/")) {
        pathname = pathname.slice(0, -1);
      }

      let cacheTags;

      // 1) Path ends with a file name + extension
      const extMatch = pathname.match(/\.([^./]+)$/i);
      if (extMatch) {
        const ext = extMatch[1].toLowerCase();
        const filename = pathname
          .split("/")
          .pop()
          .replace(/\.[^.]+$/, "")
          .toLowerCase();

        // grab all path segments except the final file name
        const pathChunks = pathname
          .split("/")
          .filter(Boolean)
          .slice(0, -1);

        cacheTags = [ext, filename, ...pathChunks];
      }
      // 2) Root "/" → "html", "home"
      else if (pathname === "/") {
        cacheTags = ["html", "home"];
      }
      // 3) Other paths without an extension → "html" + each segment
      else {
        cacheTags = ["html", ...pathname.split("/").filter(Boolean)];
      }

      return fetch(request, {
        cf: {
          cacheTags
        }
      });
    } catch (err) {
      // On error, pass through without cache tags
      return fetch(request);
    }
  }
}

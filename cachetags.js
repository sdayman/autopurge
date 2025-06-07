export default {
  async fetch(request) {
    const { pathname } = new URL(request.url);

    let cacheTags;

    // 1) Path ends with a file name + extension
    const extMatch = pathname.match(/\.([^.\/?#]+)$/i);
    if (extMatch) {
      const ext      = extMatch[1].toLowerCase();                       // "jpg", "png", etc.
      const filename = pathname
        .split("/")
        .pop()
        .replace(/\.[^.]+$/, "");                                       // name without extension

      // grab all path segments except the final file name
      const pathChunks = pathname
        .split("/")
        .filter(Boolean)                                                // ["wp-content","uploads","2020","01","file.jpg"]
        .slice(0, -1);                                                  // ["wp-content","uploads","2020","01"]

      cacheTags = [ext, filename, ...pathChunks];
    }
    // 2) Root “/” → "html", "home"
    else if (pathname === "/") {
      cacheTags = ["html", "home"];
    }
    // 3) Other paths without an extension → "html" + each segment
    else {
      cacheTags = ["html", ...pathname.split("/").filter(Boolean)];
    }

        // ←— log the tags
        console.log("Computed cacheTags:", cacheTags);

    return fetch(request, {
      cf: {
        cacheTags
      }
    });
  }
}

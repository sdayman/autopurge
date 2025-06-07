export default {
  async fetch(request) {
    const { pathname } = new URL(request.url);

    let cacheTags;

    // 1) Path ends with a file name + extension
    const extMatch = pathname.match(/\.([^.\/?#]+)$/i);
    if (extMatch) {
      const ext      = extMatch[1].toLowerCase();                 // "css", "js", etc.
      const filename = pathname.split("/").pop().replace(/\.[^.]+$/, ""); // "app" from "app.js"
      cacheTags = [ext, filename];                                // ["js", "app"]
    }
    // 2) Root “/” → "html", "home"
    else if (pathname === "/") {
      cacheTags = ["html", "home"];
    }
    // 3) Other paths without an extension → "html" + each segment
    else {
      cacheTags = ["html", ...pathname.split("/").filter(Boolean)];
    }

    return fetch(request, { cf: { cacheTags } });
  }
}

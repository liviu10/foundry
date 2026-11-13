# foundry-library — Foundry documentation website

Static documentation site for the [Foundry](../foundry/) PHP utility library, published at
`https://foundry.insightdevhub.ro`. Only the generated `public/` folder is deployed: the library
source code never goes on the server (except the download archive built from it).

- **Plain HTML + CSS + native JS + Alpine.js** (CSP build, vendored locally). No Node, no bundler, no CDN.
- **Generated from the library itself**: class pages come from the source docblocks (via PHP Reflection);
  categories and one-line overviews come from `../foundry/README.md`. Guides are hand-written Markdown.
- **SEO**: one real URL per page, unique titles and descriptions, canonical links, Open Graph / Twitter
  cards, JSON-LD (`WebSite`, `SoftwareSourceCode`, `TechArticle`, `BreadcrumbList`), `sitemap.xml`
  with real `lastmod` dates from git, internal links between classes and methods.
- **AI / LLM**: `llms.txt`, `llms-full.txt`, a Markdown copy of every page (`/docs/json.md`, announced
  with `<link rel="alternate" type="text/markdown">`), and a `robots.txt` that welcomes AI crawlers.
- Works without JavaScript; JS only adds search (Ctrl K or `/`), the theme toggle, the mobile menu,
  copy buttons and the "On this page" highlight.

## Build

Requirements: PHP 8.3+ with `zip`, `mbstring` and `tokenizer`; `git` (optional, for `lastmod` dates).

```bash
php build.php                                   # production build -> public/
php build.php --base-url=http://localhost:8080  # absolute URLs for a local preview
php -S localhost:8080 -t public                 # preview
```

The build deletes and regenerates `public/` completely, then prints warnings for anything that needs
attention: a class missing from the README catalog, a title that is too long, or a `Class::method()`
in a guide that does not exist.

Rebuild after every change to `../foundry/src`, `../foundry/README.md` or `content/`.

## Structure

```text
build.php              entry point
build/
  config.php           domain, paths, guide order, URL slug overrides
  Library.php          reads the library: README catalog + Reflection on src/*.php
  DocBlock.php         PHPDoc parser
  Linker.php           docblock prose -> HTML with <code> and links to classes/methods
  Markdown.php         small Markdown renderer for guides
  Highlighter.php      build-time PHP syntax highlighting (PHP's own tokenizer)
  Renderer.php         class reference HTML + Markdown
  Site.php             pages, sitemap, robots, llms.txt, search index, download archive
content/
  guides/*.md          hand-written guides (front matter: title, description, optional nav)
  titles.php           SEO title phrase per class
templates/             PHP templates (layout, home, docs index, class, guide, 404)
assets/                css, js, vendor (Alpine), img (icons, og-image)
docker/                containerized production deployment (Nginx Alpine-slim)
  compose.yaml         compose service (127.0.0.1:8083, read-only, 32MB limit)
  Dockerfile           hardened static web server image (<20MB)
  nginx.conf           single-worker config, CSP, HSTS, markdown MIME type
public/                GENERATED static site, tracked in Git for deployment
```

## Editing content

- **A class page** is generated from the class docblock and method docblocks in `../foundry/src`.
  Improve the docblock there and rebuild.
- **A guide**: edit `content/guides/<slug>.md`. New guide: create the file and add its slug to
  `guides` in `build/config.php`. Placeholders filled at build time: `{{class_count}}`,
  `{{method_count}}`, `{{download_url}}`, `{{download_name}}`, `{{download_size}}`,
  `{{download_sha256}}`, `{{extensions_table}}`.
- **A new class**: add it to the catalog in `../foundry/README.md` (category + overview) and a title
  phrase in `content/titles.php`. Without them it still builds, under "Other" with an automatic title.
- Inline code like `` `Json::decode()` `` in a guide becomes a link to that method automatically.

## Deploy

The documentation is served using an ultra-lightweight, hardened Nginx container (Alpine-slim) with no PHP runtime required on the server. The pre-built `public/` directory is committed to Git, enabling a clean GitOps workflow (`git pull` + container rebuild). The container securely binds to `127.0.0.1:8083`, which is routed to `https://foundry.insightdevhub.ro` via Cloudflare Tunnel.

### Initial Setup on the Server

1. **Clone the repository on the server**:
   ```bash
   git clone <repo-url> /path/to/utilities
   ```

2. **Start the container**:
   ```bash
   cd /path/to/utilities/foundry-library/docker
   podman compose up -d --build
   ```
   *(Or `docker compose up -d --build` if using Docker).*

3. **Verify the local binding**:
   ```bash
   curl -I http://127.0.0.1:8083/
   ```

### Deployment Workflow (Updates)

Whenever you make changes to documentation, styles, or library docblocks:

1. **On your local development machine**:
   Rebuild the static site and push to Git:
   ```bash
   cd foundry-library
   php build.php
   git add assets/ public/
   git commit -m "docs: rebuild static documentation"
   git push
   ```

2. **On the production server**:
   Pull the latest commit and rebuild the container:
   ```bash
   git pull
   cd foundry-library/docker
   podman compose up -d --build
   ```

### Container Security & Optimization Profile

- **Microscopic Footprint**: Consumes **~2.2 MB RAM** and only **2 PIDs** (1 master + 1 single worker). Total image size is ~20.7 MB.
- **Strictly Unprivileged**: Runs as non-root user `nginx` (UID 101) listening on internal port 8080.
- **Read-Only Root Filesystem**: `read_only: true` with a tiny 8 MB in-memory `tmpfs` mounted at `/tmp` for the PID file and buffers.
- **Zero Linux Capabilities**: `cap_drop: [ALL]` and `no-new-privileges:true`.
- **Resource Constraints**: Hard ceiling of 32 MB RAM (8 MB reserved) and 0.10 CPU core.
- **SEO & AI/LLM Optimized**: Serves `.md` files with `Content-Type: text/markdown; charset=utf-8`, enforces 1-year immutable caching for versioned assets (`site.css?v=...`), and serves custom 404 pages with HTTP status 404.

## After the first deploy

1. Add the domain to Google Search Console and Bing Webmaster Tools (Bing also feeds ChatGPT and
   Copilot search) and submit `https://foundry.insightdevhub.ro/sitemap.xml`.
2. Test a class page with the Google Rich Results Test (breadcrumbs) and the Open Graph preview of
   any social network.
3. Check that `/docs/json.md` is served as `text/markdown` and that an unknown URL returns the 404 page
   with HTTP status 404.

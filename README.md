# foundry-library — Foundry documentation website

Static documentation site for the [Foundry](https://github.com/liviu10/foundry) PHP utility library, published at `https://foundry.insightdevhub.ro`. Only the generated `public/` directory is served: the backend source code never runs on the server (pure static delivery via a hardened Nginx container).

- **Plain HTML + CSS + native JS + Alpine.js** (CSP build, vendored locally). No Node, no bundler, no external CDN dependencies.
- **Generated from the library itself**: class pages come from source docblocks (via PHP Reflection); categories and overviews come from the catalog. Guides are written in Markdown.
- **SEO**: canonical URLs, unique titles and descriptions, Open Graph / Twitter cards, structured JSON-LD (`WebSite`, `SoftwareSourceCode`, `TechArticle`, `BreadcrumbList`), `sitemap.xml` with real `lastmod` timestamps from Git.
- **AI / LLM**: `llms.txt`, `llms-full.txt`, alternate Markdown versions for every page (`/docs/<slug>.md`), and a `robots.txt` welcoming search and AI crawlers.
- **Zero-JS by design**: works completely without JavaScript; JS only enhances search (`Ctrl+K` or `/`), theme switching, mobile navigation, and code copy buttons.

---

## Local Build & Development

Requirements: PHP 8.3+ with `zip`, `mbstring`, and `tokenizer`.

```bash
php build.php                                   # Production build -> generates public/
php build.php --base-url=http://localhost:8080  # Build with local URLs for preview
php -S localhost:8080 -t public                 # Local PHP development server
```

The build cleans and regenerates `public/` completely. Rebuild after changes to `content/`, templates, or library docblocks.

---

## Directory Structure

```text
build.php                   Build CLI entrypoint
build/                      Generator core (DocBlock, Markdown, Highlighter, Renderer, Site)
library/                    Foundry PHP utility library source code & tests
  src/                      Standalone zero-dependency utility classes (*.php)
  tests/                    Comprehensive test suite
  README.md                 Catalog and class summaries
content/
  guides/*.md               Markdown guides (frontmatter: title, description, nav)
  titles.php                SEO title mappings per class
templates/                  PHP templates (layout, home, class, guide, docs index, 404)
assets/                     Source CSS, JS, Alpine.js (CSP), icons, and images
public/                     GENERATED static website (served by container, tracked in Git)
docker/
  docker-compose.yml        Compose service definition (symlink to compose.yaml)
  compose.yaml              Production compose definition (127.0.0.1:8083, 24MB RAM limit)
  Dockerfile                Hardened Alpine-slim container (<21MB total image)
  nginx.conf                Ultra-optimized static configuration (sendfile, rate-limiting)
  security-headers.conf     Strict A+ security headers (CSP, HSTS, COOP, CORP, Nosniff)
.dockerignore               Excludes library/, source code & Git metadata from build context
```

---

## Container Infrastructure & Deployment (Podman / Docker)

The documentation is served by an ultra-minimal, hardened Nginx container on Alpine Linux managed via **Podman** (or Docker). It listens on internal port `8080`, securely mapped to `127.0.0.1:8083` on the host, and routed through Cloudflare Tunnel to `https://foundry.insightdevhub.ro`.

### Initial Setup on the Server (`/var/www/foundry.insightdevhub.ro`)

1. **Configure Git to track the repository** (first-time only):
   ```bash
   cd /var/www/foundry.insightdevhub.ro
   git init
   git remote add origin git@github.com:liviu10/foundry.git
   git fetch origin
   git reset --hard origin/master
   git branch --set-upstream-to=origin/master master
   ```

2. **Start the container with Podman Compose**:
   ```bash
   cd /var/www/foundry.insightdevhub.ro
   podman compose -f docker/docker-compose.yml up -d --build
   ```

3. **Verify running status**:
   ```bash
   curl -I http://127.0.0.1:8083/
   ```

---

## How to Apply Updates on the Production Server

### Routine Updates vs Full Container Rebuild

There are two ways to apply changes on the production server:
1. **Fast-track Routine Updates (Zero Rebuild)**: For everyday documentation, guide, or CSS/JS updates.
2. **Full Container Rebuild**: When you modify `Dockerfile`, change compose parameters, or want a 100% clean rebuild without cache.

---

### Option A: Routine Content Updates (No Rebuild Needed)

Because `public/`, `docker/nginx.conf`, and `docker/security-headers.conf` are mounted directly into the container as read-only volumes (`:ro,z`), changes are updated directly on disk without touching or rebuilding the container:

```bash
cd /var/www/foundry.insightdevhub.ro
git pull
```

*(Optional)*: Nginx caches file descriptors in RAM for up to 60s (`open_file_cache`). To clear the cache immediately with zero downtime:

```bash
podman compose -f docker/docker-compose.yml exec foundry-docs nginx -s reload
```

---

### Option B: Full Clean Rebuild (No-Cache Rebuild)

Whenever you want to completely tear down the container, purge all cached build layers, and boot a brand new container instance from scratch:

```bash
cd /var/www/foundry.insightdevhub.ro

# 1. Stop containers and delete all ephemeral volumes / cache:
podman compose -f docker/docker-compose.yml down -v

# 2. Rebuild images without cached layers:
podman compose -f docker/docker-compose.yml build --no-cache

# 3. Boot containers in detached mode:
podman compose -f docker/docker-compose.yml up -d
```

*(Note: If your environment uses Docker instead of Podman, the exact same commands work by replacing `podman compose` with `docker compose`).*

---

## Security Hardening & Ultra-Optimization Profile

* **RAM Consumption**: Real-world footprint of just **~2.6 MB RAM** (enforced ceiling at 24 MB, 6 MB reservation).
* **Kernel Zero-Copy CPU**: Nginx leverages `sendfile`, `tcp_nopush`, and `tcp_nodelay` directly from the Linux Page Cache to the network interface, requiring near 0.0% CPU overhead.
* **Zero Disk Writes (`access_log off`)**: Eliminates SSD/NVMe write wear; access logging and analytics are handled at the Cloudflare Edge.
* **Container Hardening**:
  * `read_only: true`: The container root filesystem is mounted strictly read-only; no filesystem tampering is possible.
  * `user: "101:101"`: Runs unprivileged as the `nginx` system user (non-root).
  * `cap_drop: [ALL]`: All Linux kernel capabilities are dropped.
  * `security_opt: [no-new-privileges:true]`: Disallows privilege escalation via setuid binaries.
  * `tmpfs`: Ephemeral directories (`/tmp`, `/var/run`) are in-memory tmpfs mounted with `noexec,nosuid,nodev`.
* **Physical Webroot Isolation**: The container only mounts `public/`. All other server directories (`library/`, `build/`, `content/`, `templates/`, `assets/`, `.git`) are unmounted and physically unreachable.
* **Nginx Hardening**:
  * A+ Security Headers: Strict CSP (`default-src 'self'`), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, `Cross-Origin-Resource-Policy`, `Strict-Transport-Security`.
  * Dotfiles Blocked: Requests to `/.git`, `/.env`, `/.foundry-site` return `404 Not Found` without logging.
  * Library & Dangerous Extensions Blocked: Requests to `/library` or files ending in `.php`, `.sh`, `.sql`, `.env`, `.bak` return `404 Not Found`.
  * HTTP Method Restriction: Only `GET` and `HEAD` methods are accepted; all others return `405 Method Not Allowed`.
  * Rate Limiting & DoS Protection: 30 req/s (burst 50) and max 20 concurrent connections per IP, extracting client IP via Cloudflare's `CF-Connecting-IP`.
  * Integrated Healthcheck: `wget -q --spider http://127.0.0.1:8080/` tested every 30 seconds.

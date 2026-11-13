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
content/
  guides/*.md               Markdown guides (frontmatter: title, description, nav)
  titles.php                SEO title mappings per class
templates/                  PHP templates (layout, home, class, guide, docs index, 404)
assets/                     Source CSS, JS, Alpine.js (CSP), icons, and images
public/                     GENERATED static website (served by Docker, tracked in Git)
docker/
  compose.yaml              Production compose definition (127.0.0.1:8083, 24MB RAM limit)
  Dockerfile                Hardened Alpine-slim container (<21MB total image)
  nginx.conf                Ultra-optimized static configuration (sendfile, rate-limiting)
  security-headers.conf     Strict A+ security headers (CSP, HSTS, COOP, CORP, Nosniff)
.dockerignore               Excludes all source code & Git metadata from Docker build context
```

---

## Docker Infrastructure & Deployment

The documentation is served by an ultra-minimal, hardened Nginx container on Alpine Linux. It listens on internal port `8080`, securely mapped to `127.0.0.1:8083` on the host, routed through Cloudflare Tunnel to `https://foundry.insightdevhub.ro`.

### Initial Setup on the Server (`/var/www/foundry.insightdevhub.ro`)

1. **Configure Git to track the repository** (first-time only):
   ```bash
   cd /var/www/foundry.insightdevhub.ro
   git init
   git remote add origin git@github.com:liviu10/foundry.git
   git fetch origin
   git checkout -B master origin/master
   git reset --hard origin/master
   git branch --set-upstream-to=origin/master master
   ```

2. **Start the Docker container**:
   ```bash
   cd /var/www/foundry.insightdevhub.ro/docker
   docker compose up -d --build
   ```

3. **Verify running status**:
   ```bash
   curl -I http://127.0.0.1:8083/
   ```

---

## How to Apply Updates on the Production Server

### Trebuie să reconstruiești complet imaginea Docker?

> [!NOTE]
> **NU!** În 99% din cazuri **nu este nevoie să reconstruiești imaginea sau containerul**.
> Directorul `public/`, fișierul `nginx.conf` și `security-headers.conf` sunt montate direct ca volume read-only (`:ro,z`) în container.

### 1. Actualizări de conținut (documentație, ghiduri, fișiere din `public/`)
După ce ai dat `git push` de pe calculatorul local, pe server rulezi doar:
```bash
cd /var/www/foundry.insightdevhub.ro
git pull
```
Fișierele noi sunt prezente instantaneu pe disc și servite direct de container.

*(Opțional)* Pentru a curăța imediat memoria cache a descriptorilor Nginx (`open_file_cache` expiră automat după 60s):
```bash
docker compose -f docker/compose.yaml exec foundry-docs nginx -s reload
```

### 2. Modificări de configurare Nginx (`docker/nginx.conf` sau `security-headers.conf`)
Deoarece fișierele de configurare sunt de asemenea montate ca volume, este suficient un reload fără downtime:
```bash
cd /var/www/foundry.insightdevhub.ro
git pull
docker compose -f docker/compose.yaml exec foundry-docs nginx -s reload
```

### 3. CÂND este necesară reconstruirea imaginii (`docker compose up -d --build`)?
Reconstrucția imaginii este necesară **doar** în următoarele cazuri:
- Ai modificat `docker/Dockerfile` (de exemplu, schimbi imaginea de bază).
- Ai modificat setările de nivel inferior din `docker/compose.yaml` (porturi, limite RAM/CPU, tmpfs).
- Vrei să actualizezi pachetele de securitate ale imaginii de bază Alpine:
  ```bash
  docker compose -f docker/compose.yaml up -d --build --pull
  ```

---

## Profil de Securitate și Optimizare la Sânge

* **Memorie RAM**: Consum real de doar **~2.6 MB RAM** (limită maximă impusă la 24 MB, rezervare 6 MB).
* **CPU Zero-Copy**: Nginx folosește `sendfile` și `tcp_nopush` la nivel de kernel direct din Linux Page Cache spre rețea, fără copiere în memorie utilizator.
* **Zero Disk Writes (`access_log off`)**: Nicio operațiune I/O de scriere pe SSD; traficul este contorizat la nivel de Cloudflare Edge.
* **Hardening Container**:
  * `read_only: true`: Rădăcina containerului este montată read-only; niciun fișier nu poate fi modificat.
  * `user: "101:101"`: Rulează ne-privilegiat ca utilizator `nginx`.
  * `cap_drop: [ALL]`: Toate capabilitățile Linux de kernel sunt revocate.
  * `security_opt: [no-new-privileges:true]`: Previne escaladarea de privilegii.
  * `tmpfs`: Directoarele temporare (`/tmp`, `/var/run`) sunt montate în RAM cu `noexec,nosuid,nodev`.
* **Izolare Fizică Web**: Containerul are acces exclusiv la `public/`. Toate celelalte directoare de pe server (`build/`, `content/`, `templates/`, `assets/`, `.git`) **nu sunt montate**, fiind complet invizibile pentru container.
* **Securitate Nginx**:
  * Antete A+: CSP strict (`default-src 'self'`), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy`, `COOP`, `CORP`, `HSTS`.
  * Dotfiles blocate: `/.git`, `/.env`, `/.foundry-site` returnează `404 Not Found`.
  * Extensii periculoase blocate: `.php`, `.sh`, `.sql`, `.env`, `.bak` returnează `404 Not Found`.
  * Restricție metode: Doar `GET` și `HEAD` permise (oricare alta returnează `405`).
  * Rate Limiting: 30 cereri/s (burst 50) și max 20 conexiuni simultane per IP, cu detectare reală a IP-ului prin antetul Cloudflare `CF-Connecting-IP`.
  * Healthcheck integrat: `wget -q --spider http://127.0.0.1:8080/` la fiecare 30 secunde.

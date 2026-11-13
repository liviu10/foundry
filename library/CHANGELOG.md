# Foundry — Version History & Changelog

All notable changes to the **Foundry** standalone PHP utility library are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [v1.1.0] — 2026-11-10

### 🚀 Created & Added
- **Pre-rendered Static Documentation**: Complete build-time HTML compilation directly into `docs/index.html`. Zero runtime JSON files, zero CORS friction on `file://`, and instant first-paint performance.
- **Visual Method Cards UI**: Completely redesigned method inspection with distinct badges (`Constructor`, `Static Method`, `Method`), dedicated code blocks, and structured parameters tables.
- **Structured Parameter & Exception Inspection**: Every parameter specifies name, strict type pill, status (`required` / `optional = default`), and concise English explanation.
- **Quick Method Navigator**: One-click jump chips bar atop class references for fast scrolling and highlight pulsing.
- **Dedicated Version History & Changelog View**: Interactive in-portal release timeline accessible via `#changelog` with categorized change badges.
- **Caddy Turnkey Container**: Hardened, rootless Alpine container listening on port `9300` (~10 MB RAM footprint) optimized for headless Debian mini PCs.
- **AI & GEO Indexing**: Native `llms.txt`, `llms-full.txt`, and Schema.org `SoftwareSourceCode` JSON-LD schemas.

### 🛠️ Improved
- **100% English Source Docblocks**: Fully refactored and translated all docblocks in `src/*.php` directly at source into idiomatic, professional English for optimal IDE autocompletion and hover tooltips.
- **Zero-Hack Reflection Synchronizer**: Transparent `sync_docs.php` reflection engine that reads method comments, types, and annotations directly from source files without translation tables or intermediate dictionaries.
- **Dark Mode Ergonomics**: Fatigue-free high-contrast typography (exceeding WCAG AAA contrast ratios) designed to eliminate eye strain during extensive reading.
- **PHP 8.3 & PHP 7.4 Dual Target**: Verified standalone classes with explicit exception boundaries, preventing silent unexpected `null` or `false` returns.

### 🔒 Security Hardening
- **Timing-Safe Cryptography**: Constant-time string comparisons (`hash_equals()`) in CSRF, token signatures, and HMAC verification.
- **Hardened Database Connections**: Disabled emulated prepares (`ATTR_EMULATE_PREPARES => false`) and enforced UTF-8mb4 in `MySQL`.
- **Zip Slip & Decompression Bomb Prevention**: Strict path traversal validation and compression ratio limits in `Zip` and `Image`.
- **XXE Injection Defenses**: Disabled external entity resolution in `Xml` parser.

---

## [v1.0.0] — 2026-10-30

### 🚀 Initial Production Release
- **51 Standalone Utility Classes**: Production-grade PHP 8.3+ utility classes with zero third-party dependencies, organized across 6 functional domains:
  - **Core & Data Types**: `Arr`, `Str`, `Cast`, `Number`, `Money`, `Date`, `Json`, `Encoding`, `Serializer`, `Base64`.
  - **Database & Storage**: `MySQL`, `Oracle`, `SQLite`, `Cache`.
  - **Files & Formats**: `File`, `Csv`, `Xlsx`, `Xml`, `Zip`, `Upload`, `Image`, `Ini`.
  - **HTTP & Web Services**: `Request`, `Response`, `HttpClient`, `Session`, `Flash`, `Csrf`, `SecurityHeaders`, `BotDetection`, `Url`, `Paginator`, `Breadcrumbs`, `FormBuilder`, `View`, `Mailer`.
  - **Security & Validation**: `Validator`, `RoValidator`, `Crypt`, `Token`.
  - **Diagnostics & System**: `Debug`, `ErrorHandler`, `Log`, `Timer`, `Process`, `RateLimiter`, `Retry`, `Env`, `Config`, `Faker`.
- **Strict Typing & Explicit Exceptions**: Zero silent errors; operations either succeed completely or throw typed exceptions.

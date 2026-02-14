# Field Reports

<p align="center">
  <strong>Collect photos and data from the field</strong>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/php-%3E%3D7.4-777BB4?style=flat&logo=php&logoColor=white" alt="PHP 7.4+">
  <img src="https://img.shields.io/badge/license-GPL--3.0-blue" alt="License">
</p>

---

A customisable field data collection app. Create projects with custom option groups, share a link for mobile collection (photo + selections + note), and export as ZIP (images with embedded EXIF + map) or PDF.

## Table of Contents

- [Features](#-features)
- [Quick Start](#-quick-start)
- [Requirements](#-requirements)
- [Installation](#-installation)
- [Usage](#-usage)
- [Configuration](#-configuration)
- [Deployment](#-deployment)
- [Security notes](#-security-notes)
- [Troubleshooting](#-troubleshooting)
- [Project structure](#-project-structure)
- [License](#-license)

---

## ✨ Features

| Feature | Description |
|---------|-------------|
| **Custom option groups** | Define project-specific choices (e.g. "Category": A, B, C) |
| **Mobile-first collection** | Share a link—field workers submit reports from any device with photos |
| **Photo + metadata** | Capture images with GPS, selections, and notes in one submission |
| **ZIP export** | Download images with embedded EXIF metadata and map overlays |
| **PDF export** | Generate PDF reports (requires TCPDF via Composer) |
| **Custom URL slugs** | Choose your own project slug for collection links (e.g. `/collect/my-audit`) |
| **Map view** | Visualise reports by location on an interactive map |
| **Rate limiting** | Built-in protection against abuse (30 reports/hour per IP) |
| **Share links** | Generate time-limited export links for sharing |

---

## 🚀 Quick Start

```bash
# 1. Clone the repository
git clone https://github.com/your-org/field-reports.git
cd field-reports

# 2. Install dependencies (for PDF + EXIF)
composer install --no-dev

# 3. Ensure writable directories
chmod 755 data uploads cache 2>/dev/null || mkdir -p data uploads cache

# 4. Run with PHP built-in server
php -S localhost:8765 router.php
```

Open **http://localhost:8765** — a demo project is created automatically on first run.

---

## 📋 Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 7.4+ |
| Extensions | `gd`, `exif`, `sqlite3`, `zip` |

**PHP settings** (in `php.ini` or `.htaccess`):

- `upload_max_filesize` ≥ 10M  
- `post_max_size` ≥ 10M  
- `memory_limit` ≥ 128M  

---

## 📦 Installation

### Option A: Shared hosting (ZIP only)

1. Upload the app files (exclude `data/`, `uploads/`, `cache/`)
2. Ensure `data/`, `uploads/`, and `cache/` are writable
3. ZIP and JPG export work without Composer

### Option B: Full install (ZIP + PDF + EXIF embedding)

```bash
composer install --no-dev
```

This adds:

- **TCPDF** – PDF export
- **PEL** – EXIF metadata embedding in exported images

Export formats: **ZIP** (images with EXIF + map), **JPG** (single or multi as ZIP), **PDF** (requires TCPDF).

---

## 📖 Usage

### 1. Create a project

1. Go to the homepage and click **+ New Project**
2. Enter a project name (e.g. "Demo Project")
3. Optionally set a **URL slug** for the collection link (e.g. `demo-project`). Leave empty to auto-generate from the name.
4. Add option groups with labels and comma-separated choices (e.g. **Category**: A, B, C; **Status**: Pending, Done, Other).

### 2. Share the collection link

Share `/collect/your-project-slug` with field workers. They can:

- Take or upload photos
- Select options for each group
- Add a note
- Submit (GPS captured automatically if available)

### 3. View and export

- **List view** – Browse reports, filter, add comments
- **Map view** – See reports by location
- **Export** – Download as ZIP (images + EXIF), JPG, or PDF
- **Edit project** – Change name, slug, or option groups. Changing the URL slug will break existing collection links—you’ll be warned before saving.

### Friendly URLs

| Path | Description |
|------|-------------|
| `/` | Projects dashboard |
| `/collect/{slug}` | Mobile collection form |
| `/project/{slug}` | Project reports |
| `/project/{slug}/map` | Map view |
| `/project/{slug}/edit` | Edit project |

**Apache:** Uses `.htaccess`. Set `RewriteBase /YourSubdir/` if in a subdirectory.

---

## ⚙️ Configuration

Edit `config.php`:

| Constant | Default | Description |
|----------|---------|-------------|
| `MAX_UPLOAD_SIZE` | 10MB | Max file size per photo |
| `MAX_PHOTOS_PER_REPORT` | 3 | Photos per report |
| `RATE_LIMIT_REPORTS_PER_HOUR` | 30 | Reports per IP per hour |
| `EXPORT_TOKEN_EXPIRY_HOURS` | 1 | Share link expiry |
| `DEBUG` | `false` | Set `true` for error details (dev only) |
| `FONTS_DIR` | `assets/fonts` | Path for PDF fonts |
| `ALLOWED_MIME_TYPES` | jpeg, png, webp | Allowed upload types |

> **Production:** Keep `DEBUG` set to `false`. Never expose stack traces or internal errors.

---

## 🚢 Deployment

### Production checklist

| Step | Action |
|------|--------|
| 1 | Clone or upload the app (exclude `data/`, `uploads/`, `cache/` — created on first run) |
| 2 | Run `composer install --no-dev` for PDF export and EXIF embedding |
| 3 | Set `DEBUG` to `false` in `config.php` (default) |
| 4 | Ensure `.htaccess` is present; set `RewriteBase` if in a subdirectory |
| 5 | Make `data/`, `uploads/`, `cache/` writable by the web server (`chmod 755` or `chown www-data`) |
| 6 | Configure PHP: `upload_max_filesize` and `post_max_size` ≥ 10M |

### Apache / Nginx

**Apache:** Enable `mod_rewrite`. The `.htaccess` blocks direct access to `data/`, `uploads/`, `cache/`, `vendor/`, and `.git`.

**Nginx:** Add a `try_files` block or equivalent so requests are routed to `index.php` or `router.php` for friendly URLs.

### Subdirectory deployment

If the app lives at `https://example.com/field-reports/`:

1. Edit `.htaccess` and set `RewriteBase /field-reports/`
2. Ensure `base_url()` in `lib/url.php` resolves correctly (it uses `SCRIPT_NAME`)

---

## 🔒 Security notes

- **No authentication** — Designed for trusted/intranet use. Add HTTP auth or reverse-proxy auth if exposing publicly.
- **Rate limiting** — 30 reports/hour per IP by default; reduces abuse.
- **File uploads** — Validated by MIME type, extension, and magic bytes. Random filenames prevent path traversal.
- **SQL** — Parameterized queries throughout. User input is validated before use.
- **XSS** — Output escaped with `htmlspecialchars` / `h()`.

---

## 🔧 Troubleshooting

<details>
<summary><strong>Export fails or PDF button missing</strong></summary>

- Run `composer install --no-dev` to add TCPDF
- Check that `cache/` is writable
- For PDF: ensure a font is available (see `FONTS_DIR` in config)

</details>

<details>
<summary><strong>Upload fails or "File too large"</strong></summary>

- Increase `upload_max_filesize` and `post_max_size` in PHP (≥ 10M)
- Check `MAX_UPLOAD_SIZE` in `config.php`

</details>

<details>
<summary><strong>404 on friendly URLs</strong></summary>

- **Apache:** Enable `mod_rewrite`; ensure `.htaccess` is present
- **Subdirectory:** Set `RewriteBase /YourSubdir/` in `.htaccess`
- **PHP built-in server:** Use `php -S localhost:8765 router.php`

</details>

<details>
<summary><strong>Rate limit exceeded</strong></summary>

- Default: 30 reports per hour per IP
- Adjust `RATE_LIMIT_REPORTS_PER_HOUR` in `config.php`
- Rate limit data is stored in SQLite (`rate_limit` table)

</details>

<details>
<summary><strong>Reset to clean state / remove all data</strong></summary>

Run `php scripts/reset-demo.php` to delete all projects, reports, uploads, and cache. A fresh database with the demo project will be created on the next request.

</details>

---

## 📁 Project structure

```
├── api/           # JSON API (reports, project, export, submit)
├── assets/        # CSS, JS, fonts
├── lib/           # DB, validation, upload, export, geocode
├── scripts/       # reset-demo.php
├── config.php     # Configuration
├── router.php     # PHP built-in server router
├── index.php      # Projects dashboard
├── project.php    # Project reports & map
├── collect.php    # Mobile collection form
├── thumb.php      # Thumbnail serving
├── photo.php      # Full-size photo serving
└── .htaccess      # Apache rewrite rules
```

---

## 📄 License

GPL-3.0 License. See [LICENSE](LICENSE) for details.

---

<p align="center">
  <sub>Built for field data collection • No database setup required • SQLite</sub>
</p>

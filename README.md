# ReadPilot AI

Reading-fluency tracking for elementary teachers. A teacher runs a read-aloud
session with a student, records optional audio, runs an optional comprehension
quiz, and gets an AI-assisted diagnostic with recommended next steps.

Plain PHP 8.3 + MySQL on Apache. No framework, no build step, no bundler.

---

## Architecture

| Concern | Choice |
|---|---|
| Language | PHP 8.3 |
| Database | MySQL 8 (managed: Aiven / PlanetScale / TiDB / Render / any TCP MySQL) |
| Web server | Apache 2 (`php:8.3-apache`) |
| Object storage | Supabase Storage, private bucket, S3-compatible API |
| AI | Google Gemini, with a deterministic heuristic engine as fallback |
| Deploy target | Render (Docker). Vercel is not supported — no first-class PHP runtime. |

### Roles

`teacher` and `admin` only. There are no student logins: the student-facing
screens (`reading.php`, `quiz.php`, `results.php`) run inside the teacher's
browser for a student sitting beside them.

### Layout

```
config.php          Runtime config: env vars first, secrets file second, dev defaults last
auth-guard.php      Session bootstrap, CSRF helpers, require_teacher()/require_admin()
storage.php         Supabase S3 client: recordings + profile photos
auth.php            Login and access-request POST handler

teacher pages       index, students, sessions, progress, reading, quiz, quiz-report,
                    results, struggle-map, recommendations, recordings, resources, settings

admin pages         admin, users-admin, audit-trail-admin, settings-admin,
                    gradesec-admin, teacher-activity-admin

JSON APIs           student-api, admin-api, ai-api, recording-api,
                    recommendation-api, account-api

docker/             Apache security rules + entrypoint used by the image
database.sql        Schema + demo seed data
```

---

## Configuration

Every setting resolves in this order:

1. **Environment variable** — how Render, Docker and CI inject settings
2. **`readpilot-secrets.php` outside the web root** — how XAMPP development is set up
3. **Hard-coded development default**

Nothing in `config.php` needs editing before deploying. Copy
`readpilot.secrets.examples.php` to `C:\xampp\readpilot-secrets.php` for local work,
or set environment variables in production.

### Variables

| Variable | Required | Default | Notes |
|---|---|---|---|
| `DB_HOST` | prod | `127.0.0.1` | `MYSQL_HOST` also accepted |
| `DB_PORT` | prod | `3306` | `MYSQL_PORT` also accepted |
| `DB_NAME` | prod | `readpilot` | `MYSQL_DATABASE` also accepted |
| `DB_USER` | prod | `root` | `MYSQL_USER` also accepted |
| `DB_PASS` | prod | *(empty)* | `DB_PASSWORD`, `MYSQL_PASSWORD` also accepted |
| `DB_SSLMODE` | managed MySQL | off | `REQUIRED`, `VERIFY_CA`, … |
| `DB_SSL_CA` | optional | — | Path to a CA bundle for `VERIFY_CA` |
| `SUPABASE_S3_ENDPOINT` | for storage | — | Includes `/storage/v1/s3` |
| `SUPABASE_S3_REGION` | for storage | — | Exactly as shown in Supabase |
| `SUPABASE_S3_ACCESS_KEY` | for storage | — | Server-only |
| `SUPABASE_S3_SECRET_KEY` | for storage | — | Server-only |
| `SUPABASE_BUCKET` | for storage | — | Private bucket |
| `GEMINI_API_KEY` | optional | — | Without it, AI falls back to heuristics |
| `READPILOT_PRIVATE_DIR` | optional | see below | Where teacher ID documents are stored |
| `READPILOT_SECRETS_FILE` | optional | auto | Explicit path to the secrets file |
| `PORT` | — | `10000` | Injected by Render at runtime |

`READPILOT_PRIVATE_DIR` defaults to the XAMPP root when the project is served
from an `htdocs` folder, and to the system temp directory otherwise. Point it at
a mounted Render disk if submitted ID documents must survive redeploys.

---

## Local development (XAMPP)

1. Place the project at `C:\xampp\htdocs\readpilot`.
2. Copy `readpilot.secrets.examples.php` to `C:\xampp\readpilot-secrets.php` and
   fill in what you need. This file is outside `htdocs`, so it is never served.
3. Run `composer install` in the project folder.
4. Import the schema:
   ```bash
   mysql -u root < C:\xampp\htdocs\readpilot\database.sql
   ```
5. Browse to `http://localhost/readpilot/login.php`.

Required PHP extensions: `pdo_mysql`, `curl`, `fileinfo`, `mbstring`, `zip`,
`openssl`.

### Demo accounts

`database.sql` seeds accounts that all share the password `ReadPilot2026!`.
**Change or delete these before exposing the site to a network.**

| Email | Role |
|---|---|
| `admin@readpilot.local` | admin |
| `teacher@readpilot.local` | teacher (owns the seeded roster) |
| `elena.hernandez@readpilot.app` | teacher |

---

## Deploying to Render

### 1. Provision MySQL

Create a MySQL 8 database with any provider and note host, port, database,
username and password. Aiven, PlanetScale, TiDB Cloud and Render all work
unchanged — the app speaks plain MySQL over TCP.

Import the schema once:

```bash
mysql -h <host> -P <port> -u <user> -p <database> < database.sql
```

### 2. Configure Supabase Storage

See `GOOGLE_CLOUD_SETUP.md`. In short: create a **private** bucket, enable the
S3 protocol, generate an access key and secret, and record the endpoint, region
and bucket name.

> Supabase S3 keys bypass row-level security. They stay server-side only —
> never in browser code, screenshots, or source control.

### 3. Create the Render service

- **New → Web Service → Connect repo**
- **Runtime:** Docker
- The repo's `Dockerfile` needs no build arguments
- **Health check path:** `/login.php`

The image binds Apache to `$PORT` at startup, so no port configuration is needed.

### 4. Set environment variables

In Render: **Environment → Add Environment Variable**. Add the `DB_*`,
`SUPABASE_*`, and optionally `GEMINI_API_KEY` values from the table above.

Render injects `PORT` itself. Do not set it.

### 5. Deploy and verify

```bash
curl -I https://<your-service>.onrender.com/database.sql      # expect 404
curl -I https://<your-service>.onrender.com/_login_check.php  # expect 404
curl -I https://<your-service>.onrender.com/vendor/autoload.php # expect 404
```

All three must be refused. Then sign in as a teacher and confirm that the
dashboard, a reading session, and the recordings page all load.

### Free-tier behaviour

Render's free tier spins down after inactivity, so the first request after an
idle period takes several seconds. Supabase's free tier pauses projects after
seven days of no activity. Both are fine for evaluation and awkward for daily
classroom use.

---

## Security notes

The web root is protected in two independent layers, because the
`php:8.3-apache` base image sets `AllowOverride None` and would otherwise
ignore a project `.htaccess`:

- `.htaccess` — applies when served by Apache directly (XAMPP, local)
- `docker/apache-readpilot-security.conf` — applies inside the container and
  cannot be bypassed

Both deny `database.sql` (which contains password hashes), `vendor/`,
`composer.*`, dotfiles, `*.md`, `*.example.php` and any script under `uploads/`.
HSTS is left to the platform.

Uploaded ID documents are stored outside the document root, named randomly,
MIME-sniffed with `finfo`, and served back only through an admin-gated endpoint
that re-validates the filename pattern, resolves containment with `realpath`,
and re-sniffs the MIME type.

### Known issues

These are real and worth fixing before the app handles real student data:

1. **`delete_resource` has no ownership check** (`student-api.php`). Any
   authenticated teacher can delete any resource, including shared ones. The
   `created_by` column is selected and then discarded.
2. **Missing CSRF protection** on `student-api.php`, `account-api.php`,
   `recommendation-api.php`, `ai-api.php`, and most of `admin-api.php`. The
   token helpers exist in `auth-guard.php` and work correctly; they are simply
   not called on those endpoints.
3. **Stored XSS in `struggle-map.php`** — the file has no escaping helper at all
   and interpolates student names and flagged words straight into `innerHTML`.
4. **Broken session detail modal** (`sessions.php`) — `esc()` is declared inside
   `renderList()` but called from the sibling `openDetail()`, so clicking a
   session row throws a `ReferenceError`.
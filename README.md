# Wise Support Hub

Wise Support Hub is a PHP 8+ / MySQL 8 internal knowledge-base application. The displayed product name is **Support Hub** and its purpose is: “Your centralized source for system support, troubleshooting, and SOPs.”

The application is server-rendered PHP with a small MVC-style structure, Bootstrap 5 and Bootstrap Icons from CDN, and vanilla JavaScript. It has no Node.js or build-time runtime dependency.

## Requirements

- PHP 8.0 or newer (PHP 8.2+ recommended)
- PDO MySQL and PDO SQLite extensions
- `mbstring` and `fileinfo` PHP extensions
- MySQL 8.0+ for deployment
- Apache with `mod_rewrite` for clean URL routing, or the PHP built-in server for local evaluation
- XAMPP with PHP 8 and MySQL 8 is supported
- Network access to `cdn.jsdelivr.net` for Bootstrap 5.3.8 and Bootstrap Icons 1.13.1

## XAMPP / Apache Installation

1. Copy the `wise-support-hub` directory into the XAMPP `htdocs` directory.
2. Start Apache and MySQL from the XAMPP control panel.
3. Open phpMyAdmin or the MySQL command line.
4. Import `database/schema.sql`. It creates and selects the `wise_support_hub` database.
5. Import `database/seed.sql` into the same database.
6. Edit `config/database.php` with the local MySQL host, port, database name, username, and password. The checked-in file contains only non-secret XAMPP development defaults.
7. Ensure Apache allows overrides for the project directory so `.htaccess` routing is enabled (`AllowOverride All`) and `mod_rewrite` is loaded.
8. Open `http://localhost/wise-support-hub/public/`. For a virtual host, set the document root directly to the `public/` directory.
9. Sign in with the development account above and immediately reset it through **Administration > Users** if this environment is shared.

CLI imports are equivalent:

```bash
mysql -u root -p < database/schema.sql
mysql -u root -p wise_support_hub < database/seed.sql
```

## PHP Built-in Server / Demo Fallback

From the application directory:

```bash
USE_SQLITE=true php -S 0.0.0.0:8000 -t public
```

Open `http://localhost:8000`. The front controller is `public/index.php`.

When `USE_SQLITE=true`, or when the configured MySQL PDO connection fails, the application creates `database/support_hub.sqlite` automatically from `database/sqlite_schema.sql` and seeds the same essential sample documentation through `database/sqlite_seed.php`. Delete only that SQLite file when you intentionally want to reset the local demo. MySQL remains the deployment target.

Without `USE_SQLITE`, the application first attempts MySQL using `config/database.php`, logs the connection failure to the PHP error log, and falls back to SQLite. Production environments should monitor PHP errors and provision a valid MySQL connection rather than relying on fallback behavior.

## Database Configuration

Configuration lives only in `config/database.php`. Start from `config/database.example.php` for environment-specific deployments. Every setting supports an environment override:

```text
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=wise_support_hub
DB_USERNAME=root
DB_PASSWORD=
USE_SQLITE=false
```

Do not commit real production credentials. Apache, a process manager, or the hosting platform should inject environment variables.

## Project Structure

```text
wise-support-hub/
├── app/
│   ├── controllers/       Route handlers and authorization boundaries
│   ├── helpers/           Escaping, CSRF, rendering, and session helpers
│   ├── models/            Article and taxonomy persistence
│   └── services/          PDO connection/bootstrap and authentication
├── config/                Database configuration only
├── database/              MySQL and SQLite schemas/seeds
├── public/                Front controller, Apache routing, CSS, and JS
├── uploads/               Runtime upload directory; CSV files are not retained
└── views/                 Server-rendered public and administration views
```

## Principal Database Tables

- `roles`, `users`: RBAC identities for Admin, Editor, Support Engineer, and Viewer.
- `systems`, `modules`, `categories`, `carriers`, `tags`: normalized article classification.
- `knowledge_articles`: central typed Article, Error, SOP, or Request record with structured diagnostic, procedure, validation, escalation, ownership, status, and soft-delete fields.
- `article_tags`, `related_articles`: many-to-many tags and explicit cross-document links.
- `search_logs`: query, user, timestamp, result count, and selected result for analytics and gap reporting.
- `article_feedback`: one helpful/not-helpful response per user and article, including a required reason for negative feedback.
- `article_versions`: immutable JSON snapshots, version number, change summary, actor, and timestamp.

The MySQL schema includes foreign keys and indexes for status/type, taxonomy filters, severity, updates, error codes, feedback, and search logs. It also includes a FULLTEXT index over `title`, `error_code`, `error_message`, `description`, `root_cause`, `resolution`, `sop_steps`, and `keywords`.

## Search Behavior

Search is permission-aware. Support Engineers and Viewers only receive published, non-archived documentation; Admins and Editors can search their working content. Matching is weighted as follows:

1. Exact error code
2. Exact then partial error message
3. Exact then prefix title
4. Keywords
5. System or carrier
6. Description, root cause, resolution, and SOP text

Portable prepared `LIKE` matching provides predictable exact and partial lookup in MySQL and SQLite. On MySQL, compatible natural-language FULLTEXT results supplement those matches. Search filters include type, system, and carrier. Every submitted search records its result count. Opening a result through the result action securely associates the selected article with that user's search log. Zero-result searches appear as documentation gaps in Reports.

The dashboard guided troubleshooter maps issue context (order, shipment, label, rate, address, API, access, or configuration), system, carrier, and symptom to the same published search engine. It does not fabricate procedural answers.

## Roles and Permissions

- **Admin:** documentation CRUD/archive, versions, users, taxonomies, CSV import, analytics, and feedback reports.
- **Editor:** documentation create/edit/archive and visibility into drafts/review content. No users, taxonomies, importer, settings, or reports access.
- **Support Engineer:** published documentation, search, guided troubleshooting, and feedback.
- **Viewer:** published documentation, search, guided troubleshooting, and feedback.

Articles are archived through status and `deleted_at`; there is no unsafe hard-delete route. Create, update, and archive operations append article versions.

## CSV Importer

The Admin-only importer is labeled development-ready. It accepts UTF-8 text CSV files up to 2 MB and 500 data rows. It validates and previews data before a separate CSRF-protected import action. Uploaded files remain in PHP's temporary upload location and are not retained by the application.

Use `database/import-example.csv` as a correctly formatted development template.

Required header row:

```csv
title,system,category,carrier,error_code,error_message,cause,resolution,keywords
```

Optional columns:

```csv
type,severity,status
```

Allowed optional values are:

- `type`: `Article`, `Error`, `SOP`, `Request` (default `Article`)
- `severity`: `Critical`, `High`, `Medium`, `Low` (default `Low`)
- `status`: `Draft`, `Published`, `Under Review` (default `Draft`)

The importer creates missing system, category, and carrier taxonomy records and reports imported and failed counts. Imported records receive an initial version entry. Review taxonomy spelling and publish state before importing production data.

## Security Practices

- PDO prepared statements are used for user-controlled values.
- Every rendered dynamic value is escaped with `htmlspecialchars` unless it is application-generated markup.
- Every state-changing form uses a cryptographically random session CSRF token.
- Sessions use strict mode, cookies only, `HttpOnly`, `SameSite=Lax`, and `Secure` when HTTPS is active; session IDs rotate after authentication.
- Passwords use `password_hash` and `password_verify`; stored hashes and existing passwords are never shown in user management.
- Authorization is enforced in controllers, not only hidden in navigation.
- User status is checked at login, roles use least-privilege boundaries, and self-deactivation is blocked.
- CSV upload size, MIME type, header, enumeration values, and row count are validated.
- Articles and taxonomies archive without destroying linked operational history.
- Database errors are logged server-side while users receive a generic error state.

For production, add HTTPS enforcement, secure headers at the reverse proxy, centralized audit logging, rate limiting/login throttling, secret management, backups, dependency/CDN integrity policy, and a scheduled review of inactive users and stale documentation.

## Verification Checklist

- [ ] Import MySQL schema and seed without errors on MySQL 8.
- [ ] Start Apache/XAMPP and confirm `.htaccess` clean URL routing.
- [ ] Start the PHP built-in server with SQLite fallback and confirm auto-bootstrap.
- [ ] Sign in with the development Admin; reject a wrong password; sign out via POST.
- [ ] Verify exact search `UPS-ADDR-104` ranks the UPS postal-code error first.
- [ ] Verify partial search `PostalCode payment` returns the same article.
- [ ] Verify an unknown query creates a zero-result documentation gap.
- [ ] Open a search result and confirm the selected article is recorded in Reports.
- [ ] Submit Yes feedback; submit No with an empty reason and confirm validation; then submit a reason.
- [ ] Create a Draft article, update it, confirm two version entries, publish it, and archive it.
- [ ] Confirm Support Engineer and Viewer roles cannot see drafts or enter administration.
- [ ] Confirm Editor can manage articles but receives 403 for users, taxonomies, importer, and reports.
- [ ] Create/edit/deactivate a test user and reset its password without exposing an existing hash.
- [ ] Create then archive a taxonomy entry and confirm linked article relationships remain.
- [ ] Validate a CSV with missing headers and invalid enumerations; preview/import a valid file and confirm counts.
- [ ] Exercise issue-type/system/carrier guided troubleshooting and open a returned article.
- [ ] Check desktop, tablet, and mobile layouts with keyboard navigation and visible focus states.
- [ ] Run `find . -name '*.php' -print0 | xargs -0 -n1 php -l`.

Do not run create/update/archive/import smoke tests against a real operational database unless the target environment has been explicitly approved for writes.

## Future Architecture

- **Semantic/AI search:** introduce a search-provider interface, asynchronous embedding/index jobs, per-document access-control metadata, retrieval citations, evaluation sets, and an audited fallback to the current deterministic search. Do not send internal content to an external model without approved data governance.
- **Microsoft Entra SSO:** add an OpenID Connect authentication adapter, tenant and group-to-role mapping, managed application secrets/certificates, just-in-time user provisioning, conditional-access alignment, and local emergency-access controls.
- **Wiki import and synchronization:** add staged adapters for CSV, JSON, and sanitized HTML, canonical source identifiers, dry-run diffs, attachment malware scanning, conflict policy, ownership mapping, incremental checkpoints, and a reversible synchronization audit trail.


To run the local host

cd "d:\ddreyes\Project\Wise-Support-Hub\wise-support-hub"
$env:PATH = "C:\xampp\php;$env:PATH"
$env:USE_SQLITE = "true"
C:\xampp\php\php.exe -S 0.0.0.0:8000 -t public

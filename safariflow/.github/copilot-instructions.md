# SafariFlow repository guidance

## Build and validation

- There is no build step, dependency manifest, automated test suite, or configured linter in this MVP.
- From the project root, check one PHP file with `php -l .\config\auth.php`.
- Check all PHP files from the project root in PowerShell with:

  ```powershell
  Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
  ```

- For local runtime setup, follow the XAMPP and database import instructions in [README.md](../README.md). The application requires Apache, MySQL, and PDO MySQL.

## Architecture

- This is a procedural, server-rendered PHP application. Each route renders its own page, and `assets/css/style.css` provides shared styling.
- `config/database.php` creates and reuses the PDO connection. It reads `SAFARIFLOW_DB_HOST`, `SAFARIFLOW_DB_NAME`, `SAFARIFLOW_DB_USER`, and `SAFARIFLOW_DB_PASSWORD`, with local XAMPP defaults.
- `database/schema.sql` defines the MySQL `saccos` and `users` tables. A user's role and optional `sacco_id` determine which workspace they can access.
- `config/auth.php` centralizes secure session setup, URL generation, HTML escaping, role checks, and role-to-dashboard routing. Pages include it with `require_once`; protected dashboards call `requireRole()`.
- `setup.php` creates the platform-owner account once, and disables setup after the first user exists. `login.php` authenticates accounts and routes them through `dashboardForRole()`.
- The platform-owner page (`owner/dashboard.php`) creates a SACCO and its first administrator in one transaction, and can activate or suspend a SACCO. Suspension is checked at sign-in and again by protected non-owner pages.
- The admin, dispatcher, marshal, and passenger pages are role-gated placeholders; queues, vehicles, routes, dispatch, and reports are not implemented yet.

## Codebase conventions

- PHP files use `declare(strict_types=1);` and include shared files using paths based on `__DIR__`.
- Use the shared `database()` PDO connection for database access and prepared statements for values. The connection enables exception mode and native prepares.
- Start sessions through `startSecureSession()`. For state-changing form requests, follow the existing CSRF pattern: keep a token in the session, include it as a hidden form field, and compare it with `hash_equals()`.
- Escape dynamic HTML with `escape()`. Cast database counts and IDs to integers when rendering them as numbers.
- Keep role names consistent with the `users.role` enum and `dashboardForRole()` mapping. Non-platform-owner accounts need a valid `sacco_id` and an active SACCO to access their workspace.
- When changing database structure, update `database/schema.sql`; keep `README.md` in sync when local setup or supported functionality changes.
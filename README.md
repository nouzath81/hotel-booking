# PHP Booking & Reservation System

A simple, self-contained booking/reservation manager with:
- Add / edit / delete bookings
- Search by customer name, phone, or booking number
- **Customer / reservation print view** (`view_booking.php`)
- **Printable bill summary / invoice** (`print_bill.php`)
- **Dashboard totals**: total invoice amount, advance collected, outstanding balance (`index.php`)
- **Room inventory & remaining rooms**: define room types (with a manual total count, or add individual room numbers for precise tracking), see live remaining-room availability for any date range including exactly which room numbers are available vs. booked (`rooms.php`), with a real-time availability + room-number suggestion check right on the booking form
- **Reports**: single-period business report — daily, monthly, or custom range — with booking totals, breakdowns by room type/status, and full petty cash entries for that period, printable (`reports.php`)
- **Account Statement**: per-customer statement showing full booking history, invoiced/paid/balance totals, printable (`statement.php`)
- **Petty Cash**: log cash in/out entries with category and description, view a printable statement with running balance, filterable by date (`petty_cash.php`)
- **Customer Due Settlement**: record partial/full payments against a booking's outstanding balance, see full payment history per booking and per customer, and a dashboard of all unpaid balances (`due_list.php`, `settle_due.php`)
- **Trend Report**: day-by-day or month-by-month breakdown of bookings + POS sales + petty cash across a date range, for spotting trends over time, printable (`business_report.php`)
- **Company Settings**: set your company name, address, phone, email, and website once — it then appears in the site header and on every printed invoice, bill, statement, and report (`settings.php`, admin only)
- **Login System**: the whole app is now behind a login screen (`login.php`), with per-user accounts and roles (`admin` / `staff`) managed from Settings
- **Guest Checkout**: final settlement, payment recording, outstanding-balance option, and Checked-Out status (`checkout.php`).
- **POS Accounts**: create/manage Cash, Card, Bank, Online and other accounts; assign each POS sale to an account (`pos_accounts.php`).
- **Full POS (Point of Sale) System**: sell products/services (minibar, laundry, room service, etc.) at a till — product catalog with stock tracking, a cart-based sales terminal, automatic stock deduction, printable receipts, sales history with date filters and totals, and the ability to void a sale (which restocks it) (`pos.php`, `pos_products.php`, `pos_sales.php`, `pos_receipt.php`)
- **AI Technologies**: local hotel intelligence module with occupancy analysis, 7-day demand forecasting, revenue insights, outstanding-balance alerts, operational recommendations, and a natural-language-style AI assistant (`ai.php`). No external API key is required.
- **Universal PDF Export**: every authenticated management page now has an **Export PDF** button that downloads a PDF snapshot of the relevant page data (`export_pdf.php`).

Uses **SQLite** (via PHP's built-in PDO) so there is nothing to install or
configure — the database file is created automatically on first run.

## Requirements
- PHP 7.4+ with either:
  - the `pdo_sqlite` extension (enabled by default in almost all PHP installs) — for the
    default zero-setup SQLite database, or
  - the `pdo_mysql` extension — if you switch to MySQL (see below).

## Run it locally
From inside the `hotel-booking-system` folder, run:

```bash
php -S localhost:8000
```

Or, if you prefer npm as a shortcut (this project is still plain PHP — no
Node runtime dependency; `npm start` just shells out to the same PHP command
above, so PHP itself must still be installed):

```bash
npm start
```

Then open **http://localhost:8000** in your browser.

## First login
A default admin account is created automatically the first time the app runs:

- **Username:** `admin`
- **Password:** `admin123`

**Change this password immediately** — log in, go to **Settings**, add a new
admin user with your own credentials, then delete (or at least stop using)
the default `admin` account. Also set your **Company Name & Contact
Details** on the same Settings page — that's what appears in the header and
on every printed invoice/bill/statement/report.

## Run it on a normal web server (Apache/Nginx + XAMPP/WAMP/etc.)
Just copy the whole folder into your webroot (e.g. `htdocs/booking-system`)
and visit it in the browser. By default it uses SQLite, so there's nothing
else to set up — the app creates a `data/booking.sqlite` file automatically
the first time it runs.

Make sure the `data/` folder (created automatically) is writable by the
web server.

## Deploying on Render (or other Docker hosts)
Render (and most PaaS providers) have **no native PHP runtime** — only
Node.js, Python, Ruby, Go, Rust, Elixir. The `npm start` script above is a
local convenience only; it won't work as a Render "Node" service because
there's no PHP installed in that environment. A `Dockerfile` is included so
you can deploy this as a **Docker** service instead:

1. In Render, create a new **Web Service**, choose **Docker** as the runtime
   (not Node), and point it at this repo. Render will build the included
   `Dockerfile` automatically — no build/start command needed.
2. Make sure the app's root directory setting matches wherever this
   `Dockerfile` actually lives in your repo (if you put this project in a
   subfolder, set Render's "Root Directory" to that subfolder).
3. **Important:** Render's filesystem is ephemeral — anything written to
   disk (including the default SQLite file in `data/`) is lost on every
   redeploy/restart. For anything beyond quick testing, switch to MySQL or
   PostgreSQL (see below) and point it at a persistent managed database
   (e.g. Render's own managed Postgres) via `config.php`.

## Switching to PostgreSQL
The database layer also supports PostgreSQL — pick it in `config.php`, no
other file needs to change.

1. Create an empty PostgreSQL database, e.g.:
   ```sql
   CREATE DATABASE hotel_booking;
   ```
2. Open `config.php` and set:
   ```php
   define('DB_DRIVER', 'pgsql'); // was 'sqlite'

   define('DB_HOST', 'localhost');
   define('DB_PORT', '5432');
   define('DB_NAME', 'hotel_booking');
   define('DB_USER', 'your_postgres_user');
   define('DB_PASS', 'your_postgres_password');
   define('DB_PGSSLMODE', ''); // set to 'require' for most managed/cloud Postgres hosts
   ```
3. Reload the app. All tables are created automatically on first connection,
   same as with SQLite/MySQL.

Notes:
- Requires the PHP `pdo_pgsql` extension.
- Your PostgreSQL user needs `CREATE`, `SELECT`, `INSERT`, `UPDATE`, `DELETE`
  privileges on that database.
- The `migrate_export.php` / `migrate_import.php` scripts below work with
  PostgreSQL the same way they do with MySQL/SQLite.

## Switching to MySQL
The database layer supports SQLite, MySQL, and PostgreSQL — you just pick
one in `config.php`, no other file needs to change.

1. Create an empty MySQL database, e.g.:
   ```sql
   CREATE DATABASE hotel_booking CHARACTER SET utf8mb4;
   ```
2. Open `config.php` and set:
   ```php
   define('DB_DRIVER', 'mysql'); // was 'sqlite'

   define('DB_HOST', 'localhost');
   define('DB_PORT', '3306');
   define('DB_NAME', 'hotel_booking');
   define('DB_USER', 'your_mysql_user');
   define('DB_PASS', 'your_mysql_password');
   define('DB_CHARSET', 'utf8mb4');
   ```
3. Reload the app. All tables (bookings, room types, POS products/sales,
   petty cash, payments, settings, users) are created automatically the
   first time it connects — same as with SQLite, no schema file to import
   by hand.

Notes:
- Your MySQL user needs `CREATE`, `SELECT`, `INSERT`, `UPDATE`, `DELETE`
  privileges on that database.
- Switching drivers points the app at a different, empty database — it
  won't carry over existing bookings/sales automatically. To move your
  existing data across, use the export/import scripts below.
- If `config.php` has the wrong MySQL credentials or the database doesn't
  exist yet, the app will show a clear connection-failed message instead
  of a blank page.

## Migrating existing data between SQLite and MySQL
Two command-line scripts move all your data (bookings, room types, POS
products/sales, petty cash, payments, settings, users) between drivers,
preserving IDs so relationships stay intact:

```bash
# 1. With config.php still pointing at your CURRENT (source) database:
php migrate_export.php
#   → writes data/export_20260101_120000.json and prints a row count per table

# 2. Edit config.php to point at the NEW (target) database
#    (e.g. change DB_DRIVER to 'mysql' and fill in credentials)

# 3. Import into the target — it auto-creates the tables first, same as normal startup:
php migrate_import.php data/export_20260101_120000.json
```

Notes:
- Both scripts only run from the command line (they refuse to run as a
  web page) since they operate on your whole database.
- `migrate_import.php` refuses to run if the target already has data in
  any of those tables, to avoid creating duplicates — pass `--force` if
  you're sure you want to import anyway (e.g. re-running after a partial
  failure).
- Back up your source database (or just keep the exported JSON file)
  before importing, in case anything needs to be re-run.
- This also works as a general backup/restore mechanism, not just for
  switching drivers — e.g. `php migrate_export.php` on a schedule gives
  you a portable JSON snapshot of the whole system.

## File overview
| File                     | Purpose                                    |
|---------------------------|---------------------------------------------|
| `config.php`               | Database driver + credentials (SQLite or MySQL) |
| `migrate_export.php`       | CLI: export all data from the current database to JSON |
| `migrate_import.php`       | CLI: import a JSON export into the current database, preserving IDs |
| `db_connect.php`          | DB connection + auto-creates all tables for whichever driver is configured |
| `includes/functions.php`  | Bill calculation & helper functions         |
| `includes/header.php` / `footer.php` | Shared layout                    |
| `index.php`                | Dashboard: list, search, delete bookings   |
| `add_booking.php`          | New booking form                           |
| `edit_booking.php`         | Edit an existing booking                   |
| `view_booking.php`         | Printable customer/reservation summary     |
| `print_bill.php`           | Printable bill/invoice                     |
| `rooms.php`                 | Manage room types & view remaining-room availability |
| `availability_check.php`    | JSON endpoint used by the booking form's live availability check |
| `reports.php`                | Date-range business report (revenue, collections, breakdowns), printable |
| `reports.php`                | Single-period report (daily/monthly/range) with bookings + petty cash detail, printable |
| `statement.php`              | Customer account statement (booking history + balances), printable |
| `petty_cash.php`             | Petty cash entries + printable running-balance statement |
| `due_list.php`                | Overview of all bookings with an outstanding balance due |
| `settle_due.php`              | Record a payment against a booking + view its settlement history |
| `login.php` / `logout.php`    | Login screen and sign-out |
| `includes/auth.php`           | Session/login guard (`requireLogin()`, `requireAdmin()`) included by every protected page |
| `settings.php`                | Company name/contact details + user management (admin only) |
| `includes/company_header.php` | Shared company name/contact block shown on every printed document |
| `pos.php`                     | POS terminal — product grid, cart, checkout |
| `pos_products.php`            | Manage POS products (name, price, stock, category) |
| `pos_sales.php`                | Sales history with date filter, totals, void |
| `pos_receipt.php`              | Printable receipt for one sale |
| `business_report.php`        | Multi-period trend table (day-by-day / month-by-month) for bookings + petty cash, printable |
| `assets/style.css`         | All styling, including print-friendly CSS  |

## Notes
- The bill is calculated as:
  `Room Total (nights × rate) + Extra Charges − Discount = Subtotal`,
  then `Subtotal + Tax% = Grand Total`, then `Grand Total − Advance Paid = Balance Due`.
- Both `view_booking.php` and `print_bill.php` have a **Print** button
  that uses the browser's print dialog (`window.print()`); the CSS hides
  navigation and buttons automatically when printing.
- Booking numbers are auto-generated as `BK<year>-<sequence>`, e.g. `BK2026-0001`.
- Recording a payment on `settle_due.php` both logs it (for history/audit) and increases
  the booking's `advance_paid`, so the balance due updates automatically everywhere —
  dashboard, reports, statements, and invoices always stay consistent.
- The POS ships with 5 sample products (bottled water, soft drink, snack pack, laundry,
  breakfast) so the terminal isn't empty on first run — edit or delete them from
  **POS → Manage Products**. Stock is deducted automatically at checkout and restored
  automatically if a sale is voided. Sale numbers are `POS<year>-<sequence>`, e.g. `POS2026-00001`.
  The default POS tax rate is set once in **Settings** and pre-fills the terminal, but can
  be overridden per sale.
- **Room numbers are optional but recommended.** A room type works fine with just a manual
  Total Rooms count. Once you add at least one individual room number to a type (on the
  Rooms page), that type automatically switches to precise tracking: its Total Rooms
  becomes automatic (count of active room numbers), and both the Rooms page and the
  booking form show exactly which room numbers are available vs. booked for the selected
  dates — plus a suggestion list when typing the booking's Room No. field. A room can be
  marked "Maintenance" to temporarily take it out of the available pool without deleting it.
  This matching is by exact room number text, so keep room numbers consistent (e.g. always
  `101`, not sometimes `Room 101`).


Room availability fix: Checked-Out bookings no longer occupy inventory, and the checkout page displays remaining/available rooms immediately after checkout.

Monthly revenue PDF report added with month selection, total revenue, collected amount, balance due, booking count, and booking-level details.

Monthly expense/profit report added with expense entry, category totals, revenue, expenses, net profit, and profit margin.

### New modules
- `ai.php` — AI Technologies / hotel intelligence dashboard.
- `export_pdf.php` — universal authenticated PDF export endpoint.
- `includes/simple_pdf.php` — dependency-free PDF generator.

## Multiple Rooms Per Customer
The New Booking screen now supports one customer booking multiple individually numbered rooms under one booking/invoice. Select several rooms with Ctrl/Cmd; the system validates each room, counts each room against availability, and stores the selected room numbers in the existing booking record for backward compatibility. For room types without individual room numbers, use Number of Rooms and the system checks the configured capacity.

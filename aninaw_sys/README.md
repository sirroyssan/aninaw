# ANINAW

**Accountability, Networked Institutional Numbers, Accounts, and Workflows**
*Every Fund Accounted For. Every Transaction Transparent.*

Milestone 1: secure sign-in, institution/role resolution, and the role-based dashboard.
Native PHP 8.2+, MySQL/MariaDB (PDO), Bootstrap 5, Bootstrap Icons, SweetAlert2. No build tools.

---

## 1. Install on XAMPP (macOS)

1. Copy the whole `aninaw_sys` folder to `/Applications/XAMPP/xamppfiles/htdocs/aninaw_sys`.
2. Start **Apache** and **MySQL** in the XAMPP manager.
3. Let Apache write the log folder. In Terminal:
   ```bash
   chmod 777 /Applications/XAMPP/xamppfiles/htdocs/aninaw_sys/logs
   ```
   If you skip this, errors go to the XAMPP PHP error log instead.

All libraries (Bootstrap, Bootstrap Icons, SweetAlert2) are in `assets/vendor/`, so the app works offline.

## 2. Create the database

1. Open **http://localhost/phpmyadmin**.
2. Create a database named `aninaw_db` with collation `utf8mb4_general_ci`.
3. Select it, open **Import**, choose `database/schema.sql`, and click **Import**.

`schema.sql` is your original dump, followed by two clearly marked ANINAW additions (see section 7):
the `platform_user_roles` table, the `participants.user_id` column, and the seed rows for
`roles`, `permissions` and `role_permissions`.

## 3. Configure

Copy `.env.example` to `.env` in the same folder. The XAMPP defaults already work:

```
DB_HOST=127.0.0.1
DB_NAME=aninaw_db
DB_USER=root
DB_PASS=
```

Without a `.env` file these same defaults are used. Set `APP_URL=http://localhost/aninaw_sys`
only if page styles or links ever look broken. `.env` is blocked from web access by `.htaccess`.

## 4. Create the first administrators (Terminal)

There is no default account and no default password. Run these from the project folder:

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/aninaw_sys

# Platform Super Administrator (manages the platform, not any institution's finances)
/Applications/XAMPP/xamppfiles/bin/php database/create_admin.php

# An institution (e.g. code PCB) and its first Institution Administrator
/Applications/XAMPP/xamppfiles/bin/php database/create_admin.php --institution
```

- The script runs only from the command line; a browser gets a 404, and `database/` is blocked by `.htaccess`.
- Passwords are typed by you (hidden), must be 10+ characters with letters and numbers, and only the hash is stored.
- Once a Platform Super Administrator exists, the first command refuses to run again unless you add `--allow-additional`.
- `--institution` can also promote an existing user: enter their username when asked.

Then open **http://localhost/aninaw_sys** and sign in with the username or e-mail.

## 5. Giving other people access (until the Users module is built)

The Users and Roles screens come in the next milestone. Until then, add accounts in phpMyAdmin → SQL.
Generate a password hash first in Terminal:

```bash
/Applications/XAMPP/xamppfiles/bin/php -r 'echo password_hash("TheirPassword123", PASSWORD_DEFAULT), PHP_EOL;'
```

```sql
-- 1. The login account
INSERT INTO users (username, email, password_hash, first_name, last_name, status)
VALUES ('jdelacruz', 'jdelacruz@school.edu.ph', '<paste hash>', 'Juan', 'Dela Cruz', 'active');

-- 2. Membership in an institution (status must be 'active')
INSERT INTO institution_memberships (institution_id, user_id, membership_status, joined_at)
SELECT i.id, u.id, 'active', NOW() FROM institutions i, users u
WHERE i.institution_code = 'PCB' AND u.username = 'jdelacruz';

-- 3. One or more roles. Codes: institution_admin, treasurer, event_organizer, cashier,
--    collector, adviser, auditor, member, advance_recipient
INSERT INTO membership_roles (membership_id, role_id)
SELECT m.id, r.id FROM institution_memberships m
JOIN users u ON u.id = m.user_id JOIN institutions i ON i.id = m.institution_id
JOIN roles r ON r.role_code = 'treasurer'
WHERE u.username = 'jdelacruz' AND i.institution_code = 'PCB';

-- 4. Students/members only: link the login to their participant record
UPDATE participants p JOIN users u ON u.username = 'jdelacruz'
SET p.user_id = u.id WHERE p.participant_number = '2026-0001' AND p.institution_id = 1;
```

## 6. How access works

| Step | Rule |
|---|---|
| Sign-in | Username **or** e-mail + password, checked with `password_verify()`. Wrong username and wrong password give the same message: "Invalid login credentials." |
| Account status | Only `users.status = 'active'` can sign in. The status message is shown only after a correct password. |
| Workspaces | A user can work in an institution only with an **active** membership in an **active** institution and at least one institution role. Platform roles (`platform_user_roles`) give a separate *Platform Administration* workspace. |
| Several workspaces | The user picks one on *Choose Workspace*; they can switch later from the user menu. Only workspaces read from the database for that user can be selected. |
| Permissions | Read from `membership_roles → role_permissions → permissions` on **every request**. Removing a role or suspending a membership takes effect on the next page load. |
| Multiple roles | Permissions combine; the dashboard shows the panels of every role. |
| Platform admin | Sees platform counts only. Gets no institution access unless explicitly given a membership. |

### Dashboard panels by role

| Role (role_code) | Dashboard shows |
|---|---|
| Platform Super Administrator (`platform_super_admin`) | Institutions by status, users, recent institutions, platform activity |
| Institution Administrator (`institution_admin`) | Institution profile, members by role, active events/funds/groups, financial summary (read-only), recent activity |
| Treasurer / Finance Officer (`treasurer`) | Collections, paid expenses, outstanding advances, fund positions, items to reconcile, recent turnovers and transactions, ledger |
| Event Organizer (`event_organizer`) | Own events (`events.created_by`), expected vs collected, paid expenses, pending tasks |
| Faculty Cashier (`cashier`) | Collections received, turnovers to receive/verify (declared vs received), receipts issued, discrepancies |
| Class President / Collector (`collector`) | Officer groups, collection requirements per event/group, remaining amount, own turnovers and their status |
| Adviser (`adviser`) | Advised groups, collection progress, turnover status, open discrepancies |
| Auditor (`auditor`) | Financial summary, journal entries with debit/credit balance check, reconciliation status, discrepancies, audit log; no edit permissions |
| Student / Member (`member`) | Own obligations, balance, payments and receipt numbers (needs `participants.user_id` link) |
| Cash Advance Recipient (`advance_recipient`) | Own advances: released, verified/submitted expenses, returns, outstanding, missing receipts |

### How figures are calculated (no invented numbers)

- **Collections**: payments with status `posted` only (pending, reversed and refunded are excluded).
- **Collected per obligation/event/group**: `payment_allocations` of posted payments; group totals use `EXISTS` on active `group_memberships`, so a student is never counted twice.
- **Fund position**: posted `cash_flow_transactions` (inflows − outflows). Deposits between two accounts of the same fund cancel out; pending entries are excluded. Opening balances count only when posted as an `opening_balance` transaction; the recorded `fund_accounts.opening_balance` is shown separately.
- **Outstanding advance**: released − verified liquidation expenses − confirmed returns.
- **Paid expenses**: expenses with status `paid`.
- Empty tables show an empty state, never a zero dressed up as data.

## 7. Database changes and why

Everything is additive; no original column or row is changed.

1. **`platform_user_roles` (new table).** `roles.scope_type` allows `platform`, but `membership_roles` can only attach roles to an institution membership. Without this table a Platform Super Administrator would have to belong to some institution, mixing platform and institution privileges.
2. **`participants.user_id` (new nullable column, unique per institution).** The original schema has no link between a login and a student/member record. Matching by e-mail would let anyone with a matching address see another person's balance, so the link is explicit.
3. **Seed data** for `roles` (10), `permissions` (39) and `role_permissions`. The original dump had these tables empty. The seed matches rows by code, so re-running it is safe.

## 8. Security summary

- PDO prepared statements everywhere, with emulation off.
- `password_hash()` / `password_verify()`, automatic rehash, and timing-equalised checks for unknown users.
- Session: HttpOnly, SameSite=Lax, Secure on HTTPS, strict mode, ID regenerated at sign-in and on workspace switch.
- Server-side session record (`user_sessions`) checked on every request and revoked at sign-out, so a copied cookie stops working.
- Inactivity timeout (30 min) and absolute timeout (8 h), with an on-screen warning before sign-out.
- CSRF token on every POST (sign-in, sign-out, workspace switch, keep-alive). Sign-out works only by POST.
- Throttling via `login_attempts`: 5 failures per account or 30 per IP within 15 minutes block further attempts, plus a growing delay after each failure.
- Post-login redirects accept only local paths inside the app (no open redirects).
- `audit_logs` records sign-in success/failure/throttling, status and access denials, sign-out, expiry, workspace selection and switching, permission denials, and admin setup.
- Content-Security-Policy, X-Frame-Options, nosniff and Referrer-Policy headers; all output escaped.
- `.htaccess` blocks `config/`, `includes/`, `database/`, `logs/`, `uploads/`, dotfiles, `.sql`/`.md`/`.log` files and directory listing.
- Users see plain-language 403/404/419/500/503 pages; technical details go only to `logs/app-YYYY-MM.log`.

## 9. Testing checklist

| # | Test | Expected |
|---|---|---|
| 1 | Open `/aninaw_sys/dashboard.php` signed out | Redirect to sign-in |
| 2 | Wrong password, then unknown username | Same "Invalid login credentials." |
| 3 | 5 wrong passwords for one account | 6th attempt: "Too many failed sign-in attempts…" even with the right password |
| 4 | Set a user to `disabled` and sign in | "Your account is disabled…" |
| 5 | User with no active membership | "…does not have active access to any institution" |
| 6 | User in two institutions | *Choose Workspace* page; switching works from the user menu |
| 7 | Suspend that membership while signed in | Next page load leaves that institution |
| 8 | Sign out, then press Back / reuse the old cookie | Sign-in page; the old session is revoked |
| 9 | Remove the `_csrf` field (browser dev tools) and submit | "Page Expired" (419) |
| 10 | Wait 30 minutes idle | Warning, then sign-out with "Session expired" |
| 11 | Each role | Only its own panels and sidebar sections |
| 12 | Student without `participants.user_id` | "not linked to a member record yet" |
| 13 | Open `/aninaw_sys/config/config.php` or `/aninaw_sys/database/schema.sql` | 403 |
| 14 | Narrow the browser to phone width | Sidebar collapses behind the ☰ button |

## 10. Folder structure (this milestone)

```
aninaw_sys/
├── .htaccess                    # Blocks internal folders/files, error pages, headers
├── .env.example                 # Copy to .env (database & session settings)
├── README.md
├── index.php                    # Redirects to dashboard or sign-in
├── login.php                    # Sign-in page (AJAX + no-JS fallback)
├── logout.php                   # POST + CSRF sign-out, revokes session
├── select_institution.php       # Choose / switch workspace (added)
├── session_ping.php             # "Stay signed in" keep-alive (added)
├── dashboard.php                # Role-based dashboard
├── 403.php, 404.php             # Error pages (added)
├── config/
│   ├── config.php               # .env loading, constants, bootstrap
│   ├── db.php                   # PDO connection + query helpers
│   ├── session.php              # Hardened session start/destroy
│   └── .htaccess
├── includes/
│   ├── header.php               # <head>, top bar, notifications, user menu
│   ├── sidebar.php              # Role-based navigation
│   ├── footer.php               # Scripts, logout form
│   ├── functions.php            # Escaping, URLs, CSRF, flash, formatting, logging, UI helpers
│   ├── auth_check.php           # Include at top of protected pages
│   ├── auth.php                 # Login, throttling, sessions, workspaces (added)
│   ├── permissions.php          # can(), require_permission(), navigation (added)
│   ├── audit.php                # audit_logs writer (added)
│   ├── dashboard_data.php       # All dashboard queries (added)
│   ├── error_page.php           # Error page template (added)
│   ├── dashboard/               # One view per dashboard panel (added)
│   │   ├── platform.php  overview.php  finance.php  events.php  cashier.php
│   │   └── collector.php adviser.php   ledger.php   activity.php member.php advances.php
│   └── .htaccess
├── assets/
│   ├── css/style.css            # Theme variables, forms, sign-in, error pages
│   ├── css/admin.css            # Dashboard layout
│   ├── js/app.js                # SweetAlert2 helpers, sidebar, logout, idle warning
│   ├── js/validation.js         # Sign-in validation and submission
│   ├── images/logo.png, favicon.svg
│   └── vendor/                  # Bootstrap 5.3.3, Bootstrap Icons 1.11.3, SweetAlert2 11.14.5 (added)
├── database/
│   ├── schema.sql               # Original dump + ANINAW additions + seed
│   ├── create_admin.php         # CLI administrator setup (added)
│   └── .htaccess
├── logs/                        # Private application log (added)
└── uploads/receipts/, uploads/attachments/   # Protected
```

**Not in this milestone:** `password_reset.php` (needs an e-mail sender to be configured) and the module
folders (`institutions/`, `users/`, `funds/`, `events/`, `collections/`, `turnovers/`, `expenses/`,
`advances/`, `liquidation/`, `cash_flow/`, `deposits/`, `transfers/`, `reconciliation/`, `reports/`).
They show in the sidebar as **Soon** for the roles that will use them, and get built in later milestones.

# ANINAW

**Accountability, Networked Institutional Numbers, Accounts, and Workflows**
*Every Fund Accounted For. Every Transaction Transparent.*

A fund accountability system for schools and organisations: collections and official receipts,
cash custody and turnovers, deposits and fund transfers, expenses, cash advances and liquidation,
a double-entry ledger, budgets, twenty financial reports, notifications and a full audit trail.

Native PHP 8.2+ (no framework), MySQL/MariaDB with PDO, Bootstrap 5, Bootstrap Icons, SweetAlert2,
Chart.js and vanilla JavaScript. No build tools; every library is in `assets/vendor/`, so it runs offline.

Design decisions, the accounting model, schema gaps and module status are listed in
[`IMPLEMENTATION_CHECKLIST.md`](IMPLEMENTATION_CHECKLIST.md).

---

## 1. Install on XAMPP (macOS)

1. Copy the whole `aninaw_sys` folder to `/Applications/XAMPP/xamppfiles/htdocs/aninaw_sys`.
2. Start **Apache** and **MySQL** in the XAMPP manager.
3. Let Apache write the log and upload folders. In Terminal:
   ```bash
   cd /Applications/XAMPP/xamppfiles/htdocs/aninaw_sys
   chmod 777 logs uploads/receipts uploads/attachments
   ```
   Uploads (receipts, deposit slips, liquidation documents) are stored under `uploads/`, which is
   blocked from direct web access; files are only served through `attachments/download.php` after a
   permission check.

## 2. Create or upgrade the database

**New installation**

1. Open **http://localhost/phpmyadmin** and create a database named `aninaw_db` (collation `utf8mb4_general_ci`).
2. Select it, open **Import**, choose `database/schema.sql`, and click **Import**.

`schema.sql` is the original dump followed by clearly marked ANINAW additions (section 8):
Part 1 (platform roles, participant login link), Part 2 (roles and permissions seed) and
Part 3 (migration 002 for the financial modules).

That is all for a new installation. **Do not import the migration file below**: `schema.sql`
already contains it (Part 3).

**Only if you are upgrading** a database that was imported from the *milestone 1* `schema.sql`
(before the financial modules existed) and you want to keep its data:

Import `database/migration_002_financial_modules.sql` once into `aninaw_db`
(phpMyAdmin → Import, or `mysql -u root aninaw_db < database/migration_002_financial_modules.sql`).
It only adds columns, a table and permission rows; no existing data is changed. Anything that
already exists is skipped, so running it on an up-to-date database does no harm.

## 3. Configure

Copy `.env.example` to `.env` in the same folder. The XAMPP defaults already work:

```
DB_HOST=127.0.0.1
DB_NAME=aninaw_db
DB_USER=root
DB_PASS=
```

Without a `.env` file these defaults are used. Set `APP_URL=http://localhost/aninaw_sys` only if
page styles or links look broken. `.env` is blocked from web access by `.htaccess`.

## 4. First administrators (Terminal)

There is no default account and no default password.

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/aninaw_sys

# Platform Super Administrator (creates institutions; no access to institution finances)
/Applications/XAMPP/xamppfiles/bin/php database/create_admin.php

# An institution (e.g. code PCB) and its first Institution Administrator
/Applications/XAMPP/xamppfiles/bin/php database/create_admin.php --institution
```

Then open **http://localhost/aninaw_sys** and sign in with the username or e-mail.
Institutions can also be created from the platform workspace (**Institutions → New Institution**).

## 5. Setting up an institution

Signed in as the Institution Administrator:

1. **Institution Settings**: receipt title and footer, expense categories, default liquidation days and ledger account codes/names.
2. **Users**: add members, give them roles (Treasurer, Event Organizer, Cashier, Collector, Adviser, Auditor, Member, Cash Advance Recipient). New users receive a one-time link (valid 48 hours) to set their own password; the administrator never sees or types it.
3. **Departments & Groups** and **Participants** (one by one or CSV import), with group officers and advisers.

Signed in as the Treasurer:

4. **Funds** and **Financial Accounts**: create funds, cash boxes (each with a custodian), bank and e-wallet accounts, map accounts to funds and post opening balances from a count or statement.
5. **Events**: the organizer creates the event, links funds and groups (collector and a different cashier per group) and collection rules; obligations are generated for eligible participants.

Day-to-day work then follows the workflows in section 6.

## 6. Modules and workflows

| Module | Where | Who (permission) | Rules enforced on the server |
|---|---|---|---|
| Collections | `collections/` | collectors, cashiers, treasurer (`collections.record`, `receipts.issue`) | partial payments only when the rule allows; no overpayment; one official receipt per payment; reprints logged; reversal needs `payments.reverse` and a reason, voids the receipt and restores the obligation |
| Cash turnovers | `turnovers/` | collector submits (`turnovers.submit`), assigned cashier counts (`turnovers.receive`) | a payment is turned over once; declared and counted amounts stay separate; differences become discrepancies resolved by a third person |
| Deposits | `deposits/` | `deposits.manage` | pending until a different person confirms; moves cash between accounts, not income |
| Fund transfers | `transfers/` | request `transfers.manage`, approve `transfers.approve` | approver ≠ requester; destination must be mapped; no revenue or expense |
| Expenses | `expenses/` | record `expenses.record`, approve `expenses.approve` | receipt required to submit; approver ≠ recorder; posted only when paid from an account with enough cash; reversals keep the original |
| Cash advances | `advances/`, `liquidation/` | request `advances.request`, approve `advances.approve`, release `advances.release`, review `liquidation.review` | nobody approves, releases or reviews their own advance; only verified receipts and confirmed returns reduce the balance; cannot be liquidated while a balance remains; overdue after the deadline |
| Cash flow and reconciliation | `cash_flow/`, `reconciliation/` | `cashflow.view`, `reconciliation.manage` | ledger vs register per account; physical counts documented, never edit balances |
| General ledger | `ledger/` | view `ledger.view`, adjusting entries `ledger.post` | every entry balances; adjusting entries balance per fund and are posted by a different officer; corrections by reversal only |
| Budgets | `budgets/` | prepare `budgets.manage`, approve/revise `budgets.approve` | approver ≠ preparer; revisions need a reason and keep the previous figures; actual = paid + verified, pending shown apart |
| Reports | `reports/` | `reports.view` (+ `audit.view` for the audit trail) | 20 reports, institution-scoped, with filters, totals, CSV and print layouts |
| Notifications | bell menu, `notifications/` | everyone | sent only to people whose role allows them to act; daily deadline reminders |

### How figures are calculated

- **Source of truth**: posted `journal_entries` / `journal_lines`. Every cash line also writes a `cash_flow_transactions` row, and the integrity checks (`ledger/integrity.php`) confirm both agree.
- **Fund total** = cash held in its accounts + cash advances not yet liquidated. Collected = Collections account credits; spent = Expenses + Cash Shortages debits.
- **Collected per event/obligation**: allocations of posted payments only; reversed payments are excluded.
- **Outstanding advance** = released − verified liquidation expenses − confirmed returns.
- **Budget actual** = paid expenses + verified liquidation receipts in the budget's fund, event, period and category; pending items are reported separately.
- Money is handled as integer centavos in PHP and `DECIMAL(12,2)` in the database (no floating point).
- Pending, approved-but-unpaid, reversed and voided items are always labelled and never added to posted totals.

## 7. Roles and dashboards

| Role | Dashboard shows |
|---|---|
| Platform Super Administrator | Institutions by status, users, recent institutions, platform activity |
| Institution Administrator | Institution profile, members by role, financial summary, pending approvals, budgets, recent activity |
| Treasurer / Finance Officer | Collections, expenses, advances, fund positions (ledger), needs-attention items, pending approvals, budget utilisation, recent turnovers and transactions, ledger |
| Event Organizer | Own events, expected vs collected, expenses, pending tasks |
| Faculty Cashier | Collections received, turnovers to receive/verify, receipts issued, discrepancies |
| Class President / Collector | Groups, collection progress per event, own turnovers |
| Adviser | Advised groups, collection progress, turnovers, open discrepancies |
| Auditor | Financial summary, journal balance check, reconciliation status, discrepancies, audit log (read-only) |
| Student / Member | Own obligations, payments and receipts |
| Cash Advance Recipient | Own advances, receipts, returns and outstanding balance |

Permissions are reloaded from the database on every request, so role changes take effect on the next page.

## 8. Database changes and why

All changes are additive; no original column or row is modified.

| Change | Why |
|---|---|
| `platform_user_roles` (table) | Platform administrators without an institution membership |
| `participants.user_id` | Explicit link between a login and a student record (no matching by e-mail) |
| Seed: roles, permissions, role_permissions | The original dump had these tables empty |
| `financial_accounts.custodian_membership_id` (migration 002) | Who physically holds each cash box/account |
| `file_attachments.entity_type`, `entity_id` (migration 002) | Link deposit slips, receipts and liquidation documents to their record for authorised downloads |
| `event_organizers` (migration 002) | One or more organizers per event |
| New permission codes (migration 002) | e.g. `payments.reverse`, `transfers.approve`, `ledger.post`, `budgets.*` |

Gaps handled without schema changes (reprint history, rejection reasons and budget revisions in
`audit_logs`; ledger account codes in `institution_settings`, etc.) are listed in the checklist.

## 9. Security

- PDO prepared statements (no emulation); every query filtered by the active institution.
- Permissions and resource rules checked on the server for every action (e.g. collectors see only their groups, custodians use only their own accounts); role names, IDs, amounts and statuses from the browser are never trusted.
- Segregation of duties: approver ≠ requester for expenses, transfers, advances, budgets and adjusting entries; deposit confirmer ≠ recorder; discrepancies resolved by a third person; no self-role changes; the last administrator cannot be removed.
- CSRF token on every POST; one-time form tokens stop double submission; row locks (`SELECT … FOR UPDATE`) and "post once per source" checks prevent duplicate postings under concurrency.
- Posted records are never deleted or edited: reversals with reasons keep the original visible.
- Attachments: PDF/JPG/PNG/WEBP only, checked by content (finfo), 5 MB limit, random server-side names, duplicate-receipt detection, stored under a blocked folder and served only after an access check.
- Passwords: `password_hash()`, 10+ characters with letters and numbers, one-time reset links stored as hashes. Audit logs never contain passwords or tokens.
- Sessions: HttpOnly, SameSite, strict mode, regenerated IDs, server-side revocation, idle and absolute timeouts; sign-in throttling.
- CSV exports neutralise spreadsheet formulas, name the institution, filters and generator, and are audited.
- Headers: Content-Security-Policy, X-Frame-Options, nosniff, Referrer-Policy. Users see plain-language error pages with a reference number; details go to `logs/`.

## 10. Testing

### Automated tests

The suite builds a fresh test database with fictitious data (it refuses any database whose name does
not end in `_test`) and runs 94 tests: money handling, isolation between institutions, every workflow
above, ledger integrity, budgets and reports.

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/aninaw_sys
DB_NAME=aninaw_test /Applications/XAMPP/xamppfiles/bin/php tests/run_tests.php
```

With XAMPP's `root` user no other setup is needed. With another database user, grant it access first:
`GRANT ALL ON aninaw_test.* TO 'user'@'localhost';` and add `DB_USER=user DB_PASS=…` before the command.

### Manual checks by role

Use two browsers (or a private window) to act as two people at once.

| # | Steps | Expected |
|---|---|---|
| 1 | Collector records ₱50 of a ₱150 obligation three times | Each payment gets a receipt; obligation becomes Paid; no fourth payment accepted |
| 2 | Collector submits a turnover; cashier counts ₱10 less; collector acknowledges | Discrepancy opened; declared and counted amounts both shown; resolved only by a third person |
| 3 | Submit the same form twice (double-click, or Back and resubmit) | "Already submitted"; only one record |
| 4 | Treasurer records an expense, then tries to approve it | Refused: another approver must decide |
| 5 | Admin approves; treasurer pays from an account with too little cash | "Insufficient funds" |
| 6 | Organizer requests an advance; it is approved and released; organizer adds a receipt and returns the rest | Balance falls only after the reviewer verifies the receipt and the custodian confirms the return; status becomes Liquidated at zero |
| 7 | Treasurer prepares an adjusting entry and tries to post it | Refused; a second ledger officer posts it; it can then only be reversed with a reason |
| 8 | Open **General Ledger → Integrity Checks** | All checks pass on data entered through the app |
| 9 | Open any report, filter, export CSV and print | Header shows institution, period, filters and status; totals match the screen |
| 10 | Sign in to another institution and open a record URL from the first | 404 Record Not Found |
| 11 | Paste an attachment's storage path in the address bar | 403; downloading through the app works only for authorised users |

## 11. Folder structure

```
aninaw_sys/
├── index.php, login.php, logout.php, select_institution.php, session_ping.php
├── account.php, password_reset.php, 403.php, 404.php
├── dashboard/        index.php                     Role-based dashboard
├── institutions/     index.php create.php view.php settings.php
├── users/            index.php create.php edit.php roles.php
├── groups/           index.php departments.php view.php
├── participants/     index.php form.php view.php import.php
├── funds/            index.php create.php view.php accounts.php account.php
├── events/           index.php create.php view.php
├── collections/      index.php record_payment.php payment.php payments.php print_receipt.php verify_receipt.php
├── turnovers/        index.php submit.php verify.php view.php
├── deposits/         index.php view.php
├── transfers/        index.php view.php
├── cash_flow/        index.php
├── reconciliation/   index.php
├── expenses/         index.php record_expense.php view.php
├── advances/         index.php request.php release.php view.php
├── liquidation/      index.php submit.php review.php
├── ledger/           index.php entry.php trial_balance.php account.php adjust.php integrity.php
├── budgets/          index.php form.php view.php
├── reports/          index.php view.php fund_statement.php collection_summary.php event_financial.php
│                     financial_summary.php audit_trail.php
├── notifications/    index.php
├── attachments/      download.php                  Authorised file downloads
├── includes/         header, sidebar, footer, auth, permissions, audit, ui, finance, attachments,
│   │                 notifications, reports, dashboard_data, dashboard/ panels
│   └── services/     users institutions organization funds events payments turnovers movements
│                     expenses advances ledger budgets
├── config/           config.php db.php session.php
├── assets/           css/ js/ (app, validation, finance, charts) images/ vendor/ (Bootstrap, Icons, SweetAlert2, Chart.js)
├── database/         schema.sql migration_002_financial_modules.sql create_admin.php
├── tests/            run_tests.php bootstrap.php fixtures.php cases/
├── uploads/          receipts/ attachments/        Blocked from direct access
└── logs/                                           Private application log
```

## 12. Known limitations

- **PDF export** is not built in (no approved PDF library). Every report has a print layout; use the browser's *Save as PDF*.
- **E-mail/SMS** are not configured. Notifications are in-app; password links are shown to the administrator to pass on.
- **Commitments/purchase orders** are not in the schema, so budgets show approved, actual, pending and available amounts.
- Journal numbers of prepared adjusting entries that are discarded are not reused, so the sequence can show gaps (the audit trail records each discard).
- Records inserted directly into the database (outside the app) are not posted to the ledger; the integrity checks list them so they can be corrected with adjusting entries.

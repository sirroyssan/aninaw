# ANINAW — Financial Modules Implementation Checklist

Status legend: ✅ implemented and tested · 🟡 in progress · ⬜ not started

## 1. Existing functionality reused (milestone 1)

| Component | File | Reused for |
|---|---|---|
| PDO connection + query helpers | `config/db.php` | every query (prepared, native) |
| Session, login, workspaces | `config/session.php`, `includes/auth.php` | identity, institution context |
| Permission checks + navigation | `includes/permissions.php` | `require_permission()`, sidebar |
| Page guard | `includes/auth_check.php` | top of every page |
| CSRF, escaping, flash, logging, error pages | `includes/functions.php` | every form and page |
| Audit writer | `includes/audit.php` | every sensitive action |
| Layout + theme | `includes/header.php`, `sidebar.php`, `footer.php`, `assets/css/*` | all module pages |
| SweetAlert2 helpers | `assets/js/app.js` | confirmations, reasons, feedback |
| Dashboard service | `includes/dashboard_data.php` | extended with module figures |

## 2. Shared foundation added for the financial modules

| Helper | File | Purpose |
|---|---|---|
| Input, pagination, sorting, PRG + duplicate-submission tokens | `includes/ui.php` | `handle_post()`, `idempotency_field()`, `paginate()` |
| Money in integer centavos, document numbers, settings, ledger posting, reversals, balances | `includes/finance.php` | `parse_money()`, `next_document_number()`, `post_journal()`, `reverse_journal()`, `cash_balance()` |
| Secure attachments | `includes/attachments.php`, `attachments/download.php` | validated upload, authorised download |
| In-app notifications + reminders | `includes/notifications.php` | `notify_permission()`, `generate_due_reminders()` |
| Domain logic per module | `includes/services/*.php` | business rules kept out of the pages |

## 3. Accounting model

The double-entry tables in the schema (`journal_entries`, `journal_lines`) are the source of truth for balances.
`cash_flow_transactions` is the operational register; every cash line of a posted journal entry
produces exactly one register row in the same database transaction, so the two always reconcile.

| Event | Debit | Credit | Register |
|---|---|---|---|
| Opening balance | Cash (account) | Fund Balance | `opening_balance` inflow |
| Payment received | Cash (receiving account) | Collections | `collection` inflow |
| Payment reversal | Collections | Cash | mirrored rows |
| Turnover verified (custody move) | Cash (cashier account) | Cash (collector account) | `transfer` out + in, same fund — **no income** |
| Turnover shortage written off | Cash Shortages | Cash (collector account) | `adjustment` outflow |
| Turnover overage recognised | Cash (collector account) | Other Income | `adjustment` inflow |
| Deposit confirmed | Cash (destination) | Cash (source) | `deposit` out + in, same fund — **no income** |
| Fund transfer posted | Cash (to-fund) | Cash (from-fund) | `transfer` out + in — **no income or expense** |
| Expense paid | Expenses | Cash | `direct_expense` outflow |
| Advance released | Cash Advances Receivable | Cash | `advance_release` outflow |
| Advance expense verified | Expenses | Cash Advances Receivable | none (no cash moves) |
| Advance return confirmed | Cash | Cash Advances Receivable | `advance_return` inflow |
| Manual adjusting entry | as entered (must balance per fund) | as entered | `adjustment` rows for cash lines |
| Any reversal | mirror of original | mirror of original | mirrored rows; original kept |

Account codes and names are configurable per institution (Institution Settings → Ledger accounts).
Each journal line carries `fund_id`, so every balance can be reported per fund and per financial account.

Controls: amounts as integers; balanced-entry check; one posting per source record; row locks
(`SELECT … FOR UPDATE`) on the source record and financial account before posting; sufficient-cash
check before outflows; one-time form tokens against double submission; reversals instead of edits.

Adjusting entries (Batch 8) follow maker-checker: a `ledger.post` holder prepares the entry
(`journal_entries.status = 'draft'`, no effect on balances); a *different* `ledger.post` holder posts it,
after the server re-checks per-fund balance, chart accounts and available cash. Adjustments cannot use
Cash Advances Receivable (settled only through liquidation). Entries created by a module are corrected
through that module; posted adjustments are corrected by reversal. `ledger/integrity.php` verifies
balanced entries and funds, ledger vs cash register, one live posting per completed source record, and
advances receivable vs the advance records.

## 4. Schema gaps and decisions

| Gap | Decision |
|---|---|
| No custodian for financial accounts | **Migration 002** (approved): `financial_accounts.custodian_membership_id` |
| Attachments not linked to most records | **Migration 002** (approved): `file_attachments.entity_type/entity_id` |
| One creator but no organizers per event | **Migration 002** (approved): `event_organizers` |
| No receipt reprint table | Reprints recorded in `audit_logs` (`receipt.reprinted`), shown as reprint history |
| No rejection-reason columns (expenses, advances, transfers) | Reason stored in `audit_logs.reason`, shown on the record's history |
| No budget revision table | Revisions recorded in `audit_logs` with before/after values |
| No "rejected" status on cash advances | Rejected requests use `cancelled` with the reason in the audit trail |
| No chart-of-accounts table | Configurable codes in `institution_settings` (`ledger.<key>.code/name`) |
| Deposits/transfers have no fund column for pending state | Pending `cash_flow_transactions` rows hold the fund until confirmation |
| No admin-forced password change | One-time reset links via `password_reset_tokens` + `password_reset.php` |
| Commitments (purchase orders) | Not in schema; budgets show approved / posted / pending / remaining |
| Manual entries have no source record | `source_type = 'adjustment'`, `source_id` = the entry's own id; reversals point back to it |
| Discarded prepared entries | Unposted drafts are deleted; the audit log keeps their lines and reason (journal numbers may show gaps) |

## 5. Modules

| # | Module | Main tables | Key permissions | Depends on | Status |
|---|---|---|---|---|---|
| 1 | Institutions (platform) + settings | institutions, institution_settings | platform.institutions.*, institution.settings.manage | — | ✅ |
| 1 | Users, memberships, roles, reset links | users, institution_memberships, membership_roles, password_reset_tokens | users.view/manage, roles.assign | — | ✅ |
| 1 | Departments, groups, participants, officers, CSV import | departments, groups, group_memberships, participants, group_officers | organization.manage, participants.view | — | ✅ |
| 2 | Funds, access, opening balances | fund_accounts, fund_access_assignments, journal_* | funds.view/view_all/manage | ledger foundation | ✅ |
| 2 | Financial accounts, custodians, mappings | financial_accounts, fund_account_mappings | funds.manage | — | ✅ |
| 2 | Events, organizers, groups, rules, obligations, waivers | events, event_organizers, event_funds, event_group_assignments, collection_rules, event_obligations | events.view/manage | org structure, funds | ✅ |
| 3 | Payments, allocations, receipts, reprints, reversals | payments, payment_allocations, receipts | collections.record, receipts.issue, payments.reverse | events, accounts | ✅ |
| 4 | Turnovers, acknowledgments, discrepancies | cash_turnovers, cash_turnover_details, turnover_acknowledgments, turnover_discrepancies | turnovers.submit/receive, reconciliation.manage | payments | ✅ |
| 5 | Cash flow, deposits, transfers, reconciliation | cash_flow_transactions, deposits, fund_transfers | cashflow.view, deposits.manage, transfers.manage/approve, reconciliation.manage | ledger | ✅ |
| 6 | Expenses and approvals | expenses | expenses.record/approve | accounts | ✅ |
| 7 | Cash advances and liquidation | cash_advances, cash_advance_expenses, cash_advance_returns | advances.*, liquidation.* | accounts | ✅ |
| 8 | Ledger, journals, adjusting entries | journal_entries, journal_lines | ledger.view, ledger.post | foundation | ✅ |
| 9 | Budgets | budgets, budget_lines | budgets.view/manage/approve | expenses | ✅ |
| 9 | Reports (20) + CSV + print + charts | all | reports.view (+ module permissions) | all | ✅ |
| 10 | Notifications, audit trail, dashboard integration, security review, docs | notifications, audit_logs | — | all | ✅ |

## 6. Reports

| # | Report | Where | Notes |
|---|---|---|---|
| 1 | Fund balance report | `reports/fund_statement.php` | per fund: opening, collected, spent, cash, advances; chart; links to each fund statement |
| 2 | Collection summary | `reports/collection_summary.php` | per event and rule; collected = posted payments |
| 3 | Individual collection status | `reports/view.php?r=collection_status` | per participant obligation |
| 4 | Event financial report | `reports/event_financial.php` | collections, posted vs pending spending, advances, budgets; chart |
| 5 | Payment history | `reports/view.php?r=payment_history` | defaults to posted |
| 6 | Receipt register | `reports/view.php?r=receipt_register` | issued/voided, reprint counts |
| 7 | Cash turnover report | `reports/view.php?r=turnover_report` | declared vs counted |
| 8 | Cash custody report | `reports/view.php?r=cash_custody` | posted balance per account/fund/custodian; pending apart |
| 9 | Turnover discrepancy report | `reports/view.php?r=discrepancy_report` | |
| 10 | Deposit report | `reports/view.php?r=deposit_report` | defaults to confirmed |
| 11 | Fund transfer report | `reports/view.php?r=transfer_report` | defaults to posted |
| 12 | Expense report | `reports/view.php?r=expense_report` | defaults to paid |
| 13 | Cash advance report | `reports/view.php?r=advance_report` | |
| 14 | Cash advance liquidation report | `reports/view.php?r=liquidation_report` | unliquidated = released − verified − confirmed returns |
| 15 | Cash flow report | `cash_flow/index.php` | existing register (Batch 5) |
| 16 | Budget versus actual | `reports/view.php?r=budget_vs_actual` and each budget page | actual = paid + verified; pending apart |
| 17 | Journal entry report | `ledger/index.php` | Batch 8 |
| 18 | Ledger report | `ledger/trial_balance.php`, `ledger/account.php` | Batch 8 |
| 19 | Audit trail | `reports/audit_trail.php` | institution (audit.view) or platform (platform.audit.view) |
| 20 | Institution financial summary | `reports/financial_summary.php` | needs funds.view_all or ledger.view; chart |

All reports: institution-scoped queries, filters (date, fund, event, account, group/department, status,
search as relevant), pagination, totals over the whole filtered result, CSV (formula-injection safe,
with a preamble naming the institution, filters and generator; exports are audited) and a print layout
with the report header. Charts use Chart.js 4.4.4 vendored in `assets/vendor/chartjs/`.
PDF export is not included (no approved PDF library); use the browser's "Save as PDF" from the print layout.


## 7. Store module: products, inventory, college shirts and printing services

Built on the existing architecture: same auth/permission checks, `handle_post` (CSRF, idempotency,
transactions), validation helpers, audit log, notifications, attachments, report engine and ledger.
Migration 003 (`database/migration_003_products_orders.sql`, also Part 4 of `schema.sql`) is additive
and re-runnable.

| Area | Main tables | Key permissions | Status |
|---|---|---|---|
| Categories (unlimited), products and services: SKU, type, fulfillment, fund, unit, cost, price, reorder level, image, status | product_categories, products | products.view/manage, products.pricing | ✅ |
| Variants: shirt sizes, service options (A4 B&W, colour, long) with their own price/cost | product_variants | products.manage | ✅ |
| Stock: beginning inventory (once), deliveries, releases, supplies consumed, damaged/lost/returned/adjusted with reason; no negative stock without `inventory.override` (flagged) | inventory_movements | inventory.view/manage/adjust/override | ✅ |
| Supplier / production orders: ordered vs received per size, partial receipts, linked expense | stock_batches, stock_batch_items | inventory.manage | ✅ |
| Orders: several items, existing participants or walk-ins, snapshot price and cost per line, discounts with authority and reason, order slip | sales_orders, sales_order_items | orders.manage, orders.discount | ✅ |
| Order statuses (Pending → Confirmed → In Production → Ready for Release → Partially Released → Completed, or Cancelled) separate from payment status (Unpaid / Partially Paid / Fully Paid) | sales_orders | orders.manage, orders.cancel | ✅ |
| Payments: existing payments/receipts, cashier's own mapped account, never above balance, partial only when every item allows it, no automatic "paid", reversal reopens the balance | payments, receipts, sales_order_payments, journal_* | collections.record, payments.reverse | ✅ |
| Reservation at confirmation; release only when fully paid; release log with who claimed it | sales_order_releases | orders.manage | ✅ |
| Shirt production list: demand per size, on order, received, set aside, still to order; "mark orders ready" allocates oldest first | (views) | orders.manage, inventory.view | ✅ |
| Printing jobs: per page/copy/sheet/job pricing, pages × copies, instructions, file, assigned staff, Pending → In Progress → Completed → Released, supplies deducted on completion | sales_order_items, service_consumption_rules | orders.manage | ✅ |
| Ledger: Sales Revenue 4100 and Service Revenue 4200 per fund, included in fund and institution summaries | journal_lines | — | ✅ |
| Store dashboard and 10 reports | — | store permissions | ✅ |
| Roles: Inventory Custodian, Order Staff; view access for treasurer, auditor, cashier | roles, role_permissions | — | ✅ |
| Tests (`tests/cases/12_store_orders.php`) and browser end-to-end runs | — | — | ✅ |

### Store reports

| # | Report | Where |
|---|---|---|
| 1 | Product inventory | `reports/view.php?r=store_inventory` |
| 2 | Inventory movements | `inventory/movements.php` (also `?variant=`, `?product=`) |
| 3 | Shirt orders by size | `reports/view.php?r=store_shirt_sizes` |
| 4 | Supplier and production deliveries | `reports/view.php?r=store_deliveries` |
| 5 | Customer order history | `reports/view.php?r=store_customer_orders` |
| 6 | Printing services transactions | `reports/view.php?r=store_printing` |
| 7 | Sales and collections | `reports/view.php?r=store_sales_collections` |
| 8 | Unpaid and partially paid orders | `reports/view.php?r=store_unpaid` |
| 9 | Released and unclaimed items | `reports/view.php?r=store_unclaimed` |
| 10 | Product costs and gross profit (expected vs actual) | `reports/view.php?r=store_profit` |

Listed under **Store** in the reports hub and at `products/reports.php` for store staff without
`reports.view`. Same filters/totals/CSV/print behaviour as the financial reports.

### Decisions

- **Cash basis**: revenue is posted when a payment is received; order balances are tracked on the order, not as ledger receivables.
- **Customers** are participants; walk-ins are added as participants of type "other" (WALK-numbered) so receipts and history work the same way.
- All lines of one order must belong to one fund (each payment posts to one fund).
- Made-to-order items (shirts) are not reserved until received and set aside; stock items are reserved at confirmation.

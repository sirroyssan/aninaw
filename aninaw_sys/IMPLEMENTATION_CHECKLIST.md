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
| Manual adjusting entry | as entered (must balance) | as entered | rows for cash lines |
| Any reversal | mirror of original | mirror of original | mirrored rows; original kept |

Account codes and names are configurable per institution (Institution Settings → Ledger accounts).
Each journal line carries `fund_id`, so every balance can be reported per fund and per financial account.

Controls: amounts as integers; balanced-entry check; one posting per source record; row locks
(`SELECT … FOR UPDATE`) on the source record and financial account before posting; sufficient-cash
check before outflows; one-time form tokens against double submission; reversals instead of edits.

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

## 5. Modules

| # | Module | Main tables | Key permissions | Depends on | Status |
|---|---|---|---|---|---|
| 1 | Institutions (platform) + settings | institutions, institution_settings | platform.institutions.*, institution.settings.manage | — | ✅ |
| 1 | Users, memberships, roles, reset links | users, institution_memberships, membership_roles, password_reset_tokens | users.view/manage, roles.assign | — | ✅ |
| 1 | Departments, groups, participants, officers, CSV import | departments, groups, group_memberships, participants, group_officers | organization.manage, participants.view | — | ✅ |
| 2 | Funds, access, opening balances | fund_accounts, fund_access_assignments, journal_* | funds.view/view_all/manage | ledger foundation | ⬜ |
| 2 | Financial accounts, custodians, mappings | financial_accounts, fund_account_mappings | funds.manage | — | ⬜ |
| 2 | Events, organizers, groups, rules, obligations, waivers | events, event_organizers, event_funds, event_group_assignments, collection_rules, event_obligations | events.view/manage | org structure, funds | ⬜ |
| 3 | Payments, allocations, receipts, reprints, reversals | payments, payment_allocations, receipts | collections.record, receipts.issue, payments.reverse | events, accounts | ⬜ |
| 4 | Turnovers, acknowledgments, discrepancies | cash_turnovers, cash_turnover_details, turnover_acknowledgments, turnover_discrepancies | turnovers.submit/receive, reconciliation.manage | payments | ⬜ |
| 5 | Cash flow, deposits, transfers, reconciliation | cash_flow_transactions, deposits, fund_transfers | cashflow.view, deposits.manage, transfers.manage/approve, reconciliation.manage | ledger | ⬜ |
| 6 | Expenses and approvals | expenses | expenses.record/approve | accounts | ⬜ |
| 7 | Cash advances and liquidation | cash_advances, cash_advance_expenses, cash_advance_returns | advances.*, liquidation.* | accounts | ⬜ |
| 8 | Ledger, journals, adjusting entries | journal_entries, journal_lines | ledger.view, ledger.post | foundation | ⬜ |
| 9 | Budgets | budgets, budget_lines | budgets.view/manage/approve | expenses | ⬜ |
| 9 | Reports (20) + CSV + print | all | reports.view (+ module permissions) | all | ⬜ |
| 10 | Notifications, audit trail, dashboard integration | notifications, audit_logs | — | all | ⬜ |

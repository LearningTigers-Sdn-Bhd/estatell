# Daily journal

## Purpose

Push the money of each closed business date to the property's accounting software as one balanced journal, so the accountant does not retype the night audit.

## Scope

| Now                                                                                       | Later                                                                     |
| ----------------------------------------------------------------------------------------- | ------------------------------------------------------------------------- |
| QNE Cloud Accounting and Bukku, one provider per hotel                                    | More providers, each one a new `AccountingGateway` and enum case          |
| One journal per closed business date, pushed as a draft                                   | Posting the journal as approved instead of draft, once an accountant asks |
| Owner or manager sets the key, accounts and switch                                        | A scheduled retry of failed dates (today a person presses **Push again**) |
| Company invoices, credit notes and payments, see [Company billing](02-company-billing.md) | A backfill of documents issued before company billing was switched on     |
| Behind the `AccountingIntegration` feature, off by default                                |                                                                           |

## Feature gate

- Pennant feature `AccountingIntegration` (`accounting-integration`), off by default, turned on per property by a platform admin like Boats.
- When off, the accounting routes return 404, the sidebar item is hidden and `PushDailyJournal` does nothing.

## The page

Settings > Accounting has three tabs, each with its own route and controller method, and a header on every tab:

| Part       | Route                          | Shows                                                                                                                            |
| ---------- | ------------------------------ | -------------------------------------------------------------------------------------------------------------------------------- |
| Header     | all three                      | The setup status (`AccountingSetupStatus`) and three steps: Connect, Map accounts, Go live                                       |
| Connection | `settings.accounting.edit`     | Provider cards, key, subdomain, guest ledger account, test server and on/off switch, plus a Status card with **Test connection** |
| Accounts   | `settings.accounting.accounts` | Progress, search, a Not mapped filter and the codes grouped as Charge, Tax, Payment, Adjustment                                  |
| History    | `settings.accounting.history`  | The last 30 pushed dates with **Push again** for a failed one                                                                    |

- Nothing is locked. **Account codes can be mapped before the connection is saved**: they live on `transaction_codes`, not on the connection. Only **Test connection** needs a saved, complete connection.
- `AccountingSetupStatus` is worked out, never stored: Not connected (no key), Needs attention (the connection has a `last_error`), Ready (connected, switched off), Live (switched on).
- The Accounts tab loads every code once. Search and the filter only hide rows, so no request is made and every input stays in the form.

## Flow

```
 1 Flag on      Platform admin turns on Accounting integration for the property
 2 Connect      Settings > Accounting: pick the software, paste the API key, set the guest ledger account
 3 Map          Type the account code of the accounting software next to each transaction code
 4 Test         Test connection: signs in, then checks that every mapped account exists
 5 Switch on    Push closed days automatically
 6 Night audit  The audit completes, then queues PushDailyJournalJob for that date
 7 Push         PushDailyJournal builds the journal and sends it, one row per date in accounting_syncs
```

## Data model

```
accounting_connections   id, property_id (unique), provider (AccountingProvider), api_key (encrypted),
                         company_subdomain (nullable), sandbox (bool), guest_ledger_account_code (nullable),
                         enabled (bool), last_tested_at, last_error, timestamps

accounting_syncs         id, property_id, business_date_id, provider, status (AccountingSyncStatus),
                         external_id (nullable), error (nullable), attempts, pushed_at, timestamps
                         unique (property_id, business_date_id)
```

- `transaction_codes.gl_account_code` already existed and holds the account code of the accounting software. Settings > Accounting is its first editor.
- The API key is never sent to the browser. The page only learns `hasApiKey`. The key is called the access token for Bukku.
- A property has one connection, so one provider at a time. Changing the provider drops the saved key and the company subdomain, and the account codes in `gl_account_code` stay as they were: they belong to the old software, so run **Test connection** and fix the accounts.

## Enums

- `AccountingProvider`: Qne, Bukku. `gateway()` returns the strategy class for a connection. `keyLabel()`, `keyHelp()`, `needsCompanySubdomain()` and `supportsSandbox()` tell the form which words and fields to show, so the page has no provider `if`.
- `AccountingSyncStatus`: Pending, Pushed, Failed, Skipped. A Pushed or Skipped date is final. A Failed date can be pushed again.

## How the journal is built

`BuildDailyJournal` (`app/Services/Accounting/`) reads the folio ledger by `posting_date` and groups by transaction code.

| Net of the code on the date               | Journal line                                                        |
| ----------------------------------------- | ------------------------------------------------------------------- |
| Positive (charges, taxes)                 | Credit to the code's account                                        |
| Negative (payments, discounts)            | Debit to the code's account                                         |
| Charges minus payments left over (unpaid) | Debit to the guest ledger account, or a credit when guests overpaid |

- Lines copied from the old system (`migrated`) and the Opening balance code are left out, as in the daily revenue report.
- A code that nets to zero is left out. A date with no lines is **Skipped**.
- A code with an amount and no account fails the date with the codes named. Nothing is sent.
- Closed business dates never change, so a pushed date never needs a second push. Corrections post on a later date and reach the accounting software with that date.
- The reference is `EST-{property code}-{date}`.

## QNE gateway

Source: [QNE open API](https://openapi.account.qne.cloud/doc/index.html).

| Step               | Call                                                                                               |
| ------------------ | -------------------------------------------------------------------------------------------------- |
| Sign in            | `GET api/Auth/Connect?api-key=` returns a token for the company the key belongs to                 |
| Test               | `GET api/AccountCodes/Leaf/Query?$top=1`                                                           |
| Find an account id | `GET api/AccountCodes/Leaf/Query?$filter=code eq '...'`. A journal line needs the id, not the code |
| New journal        | `GET api/Journals/New` gives QNE's own currency and numbering defaults                             |
| Create             | `POST api/Journals/Create` with the template, our lines and `documentStatus` 0 (Draft)             |

- Every response is an envelope. `code` `0000` is success, anything else is a failure and the message is shown to staff.
- The base URL is `services.qne.base_url` (`QNE_BASE_URL`). QNE has no separate sandbox host that we found, so test with a trial company, never a customer's books.
- The journal is a draft so the accountant checks the first ones in QNE.
- The local amounts equal the amounts, because the property's currency is assumed to be QNE's local currency.

To verify against a real QNE company: the OData property name `code` in `$filter`, whether `Journals/Create` accepts the template unchanged, and the draft status.

## Bukku gateway

Source: [Bukku API](https://developers.bukku.my/). Specs are YAML files under `/specs/`.

| Step              | Call                                                                                                                                                     |
| ----------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Headers           | `Authorization: Bearer {access token}` and `Company-Subdomain: {subdomain}` on every call                                                                |
| Test and find ids | `GET accounts` returns every account with `id` and `code`. Read once and matched by code, so there is no filter to guess                                 |
| Create            | `POST journal_entries` with `date`, `currency_code` (the property's), `exchange_rate` 1, `number2` (our reference), `status` `draft` and `journal_items` |

- A journal item has `line`, `account_id`, `description`, `debit_amount` and `credit_amount`. The side that is not used is `null`.
- Production is `https://api.bukku.my`. **Bukku has a staging server**, `https://api.staging.bukku.dev`, and gives a test account on request (email dev@bukku.my with a login email, the staging address and 3 or 6 months). The **Use the test server** switch sends a connection there.
- The limit is 600 requests per minute. One journal a day is far below it.
- Bukku advises one consolidated entry per day over one per transaction, which is the daily journal.
- The company subdomain is the first part of the Bukku address (`example` in `example.bukku.my`).

To verify against a staging account: that `GET accounts` is not paged for a company with many accounts, and that `number2` accepts our reference.

## Actions and Jobs

- `PushDailyJournal` (Action): returns null when there is no enabled connection, the feature is off or the date is not Closed. Takes a cache lock per sync row, so two pushes never send the same date. An expected failure is saved on the row as Failed. An unexpected error throws and the job retries.
- `PushDailyJournalJob`: unique by property and date, three tries.
- `RunNightAudit` queues the job when the audit completes and the property has an enabled connection. An error in the push never changes the audit.
- `SaveAccountingConnection`, `SaveAccountMappings`, `TestAccountingConnection`: the Settings page. Each saves an activity log entry.

## Permissions and routes

`settings.manage` (Owner, Manager) behind the feature gate. Routes are under `/p/{code}/settings/accounting`: `edit`, `accounts`, `history`, `update`, `mappings`, `test` and `syncs/{accountingSync}/retry`.

## Tests

`tests/Feature/Accounting/`: the journal balances, migrated and other-date lines are left out, an unmapped code fails the date, a push happens once, a refusal is retried, a missing account sends nothing, the feature and the connection switch stop the push, the audit queues the job, the key stays out of the page, mappings are scoped to the property and another property's sync returns 404. `BukkuGatewayTest` covers the headers, the draft payload with null sides, the staging server, a missing account, a refusal and a missing subdomain. Shared setup lives in `tests/Feature/Support/AccountingSetup.php`.

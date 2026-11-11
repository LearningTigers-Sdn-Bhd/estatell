# Bank accounts

## Purpose

The bank accounts a property takes transfers into. Companies see them in the portal, where they pay a booking or their account by bank transfer and send the slip. The customer documents print them. Online payment (Curlec) for companies is not part of this (decided 11 Nov 2026).

## Data model

```
property_bank_accounts   id, property_id (FK), bank_name (varchar 100), account_name (varchar 150),
                         account_number (varchar 34), swift_code (varchar 11, nullable),
                         position, active (default true), timestamps
                         UNIQUE (property_id, account_number)
                         CHECK swift_code IS NULL OR char_length(swift_code) IN (8, 11)
payment_proofs           + bank_account_id (nullable, FK property_bank_accounts, restrict)
```

- The text fields are trimmed. `account_number` keeps the spaces staff type ("5123 4567 8901"), and the unique check compares it as saved. `swift_code` is saved in upper case; an empty one is stored as null.
- Accounts are never deleted. A proof keeps the account it names. Turning an account off hides it everywhere below.
- `SeedPropertyDefaults` adds no accounts. A new property has none until staff add one.

## Rules

- **Available** (`active`) accounts show in the portal and on documents, in `position` order. An account that is off shows nowhere outside Settings.
- Reordering moves only available accounts. An account turned on again goes to the end, like [Payment methods](04-payment-methods.md#rules).
- **Paid into:** a proof or slip may name the account the company paid into. The field shows only when the property has 2 or more available accounts. With one, the proof saves that account. With none, it saves none. The account must be an available account of the same property, or the upload is refused.

## Where accounts show

| Place                                                                                                               | What                                                     |
| ------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------- |
| Portal booking, Payments tab, **How to pay** ([Company portal](../23-company-portal/01-company-portal.md#bookings)) | Each available account, with a copy button on the number |
| Portal **Statements**, above the slip form ([Payment slips](../15-city-ledger/03-ar-payments.md#payment-slips))     | The same list                                            |
| Booking summary and AR invoice                                                                                      | The available accounts, then the **Payment notes** text  |
| Reservation summary card and the company **Payments** tab (staff)                                                   | **Paid into** on each proof or slip                      |

**Payment notes** is the Documents tab text that was called Payment details (`print_settings.bank_details`, column unchanged). It now holds other payment instructions, for example "DuitNow QR at the front desk". See [Printing settings](../02-property/05-printing-settings.md#documents-tab).

## UI

Settings › Billing setup › **Bank accounts** (`settings.manage`, icon `banknote`), after Agent payments. The page copies [Payment methods](04-payment-methods.md), without the search. The table and the Sheet form are `components/pms/settings/bank-account-table.tsx` and `bank-account-form.tsx`:

```
Bank accounts                                                     [+ Add account]
Agents see available accounts in the portal.

Available  Bank        Account name              Number · SWIFT
  [on]     Maybank     Sand Bay Resort Sdn Bhd   5123 4567 8901 · MBBEMYKL   ↑ ↓  Edit
  [on]     CIMB Bank   Sand Bay Resort Sdn Bhd   8001 2233 44 · CIBBMYKL     ↑ ↓  Edit
  [off]    RHB Bank    Sand Bay Resort Sdn Bhd   2140 5566 7788        Inactive   Edit
```

- **Available** is a Switch in the first column and saves at once.
- Up and down arrow buttons move available accounts.
- **Add account** and **Edit** open a Sheet: Bank name, Account name, Account number, SWIFT code (optional, "For agents paying from abroad").
- An empty list keeps the table header and shows "No bank accounts yet. Add the accounts companies transfer to." in one row, like Boat fares.

## Actions

| Action                | Does                                                                                                                                      |
| --------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| `CreateBankAccount`   | Adds an account at the end of the available list. Logs `bank_account.created`                                                             |
| `UpdateBankAccount`   | Saves an account, including Available. One turned on again goes to the end. Logs `bank_account.updated`, and nothing when nothing changed |
| `ReorderBankAccounts` | Saves the order of the available accounts. Refuses a list that is not exactly them. Logs `bank_account.reordered`                         |

`BankAccountRequest` validates the four fields, Available and the unique number per property. The Switch sends the account's fields with the new value, like Payment methods.

## Routes

All need `settings.manage`.

| Route                                  | Name                             |
| -------------------------------------- | -------------------------------- |
| `GET /p/{code}/settings/bank-accounts` | `settings.bank-accounts.index`   |
| `POST …/bank-accounts`                 | `settings.bank-accounts.store`   |
| `PUT …/bank-accounts/order`            | `settings.bank-accounts.reorder` |
| `PUT …/bank-accounts/{bankAccount}`    | `settings.bank-accounts.update`  |

`{bankAccount}` is scoped to the property in the URL; another property's account is a 404.

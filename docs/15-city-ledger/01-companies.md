# Companies

## Purpose

Corporates, travel agents and government bodies that stay now and pay later. All are a **company** (see the [Glossary](../overview.md#glossary)). Hotels call this the **city ledger**; the UI uses that name. Tables keep the standard `ar_*` names.

## Data model

```
companies            id, property_id, name, type (CompanyType), brn, tin, sst_registration_number,
                     contact_email, contact_phone,
                     billing_address_line1, billing_address_line2, billing_city,
                     billing_state_code, billing_postal_code, billing_country_code,
                     credit_limit, payment_terms_days, direct_bill_enabled,
                     portal_enabled, portal_cancel_cutoff_days (nullable),
                     profile_managed_by_company (bool, default false),
                     status (CompanyStatus), timestamps
                     UNIQUE (property_id, lower(name))
                     CHECK name is not blank; CHECK credit_limit >= 0; CHECK payment_terms_days >= 0
company_rate_plans   company_id, rate_plan_id
company_users        see Company portal
```

## Enums

- `CompanyType`: Corporate, TravelAgent, Government.
- `CompanyStatus`: Active, Suspended.

- Required: `name` and `type`. Every other field is optional. BRN, TIN and SST number matter for e-invoicing later.
- The tax numbers (`brn`, `tin`, `sst_registration_number`) and billing address (`billing_*`) are optional. Hotel staff may fill them in on the Details tab, until the company ticks **"Only we manage our tax and billing details"** in the [Company portal](../23-company-portal/01-company-portal.md#company-details). That sets `profile_managed_by_company`; from then on hotel staff see them read-only and `UpdateCompanyBilling` refuses a save. AR invoices print them later.
- `credit_limit` is nullable (no limit). `payment_terms_days` is nullable. `direct_bill_enabled` defaults to false. Credit holds come in [Credit control](04-credit-control-and-reports.md).
- `reservations.company_id` and `folios.payer_company_id` get foreign keys to `companies` (restrict on delete).

## Rules

- `reservations.company_id` records the billed company.
- `folios.payer_company_id` makes a company pay a folio.
- The name is unique per property, ignoring case, so one company never splits into two ledgers.
- Companies are never deleted. Invoices, folios and reservations point at them. Suspend instead.
- A Suspended company cannot be picked on a new booking or set on a reservation. Existing reservations keep it.
- Changing `reservations.company_id` is refused while the Master folio holds lines: "Move or void the master folio's charges first." Without lines, the Master payer follows the new company (see [Routing](../13-folio/03-routing.md#known-later-work)). Clearing the company follows the same rule; an empty Master folio stays, with no payer, because folios are never deleted.
- The company is picked on the [booking form](../12-reservations/05-booking-form.md) and changed from the [reservation page](../12-reservations/06-reservation-page.md) (**⋯ More › Change company**). Both need `company.view`; the change also needs `reservations.manage`. The list holds Active companies of the property. A Suspended or foreign company is refused as `company_not_available`. A reservation keeps its own Suspended company, and saving it unchanged is not a change.
- Closed (Cancelled or Completed) reservations cannot change company.
- `company_rate_plans` links negotiated derived rate plans. The booking form offers them when the company is selected.
- A plan linked to any company is hidden on the booking form until one of those companies is selected. This stops staff giving company prices to walk-ins.
- `portal_enabled`, `portal_cancel_cutoff_days` and `company_users` belong to the [Company portal](../23-company-portal/01-company-portal.md). They are edited on the company's Portal tab, with its company users and invitations.

## Actions

| Action                     | Does                                                                                                                                                                                                                                                                          |
| -------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `CreateCompany`            | Creates an Active company. Logs `company.created`                                                                                                                                                                                                                             |
| `UpdateCompany`            | Updates the fields. Logs `company.updated` with diff                                                                                                                                                                                                                          |
| `SuspendCompany`           | Active → Suspended. Logs `company.suspended`                                                                                                                                                                                                                                  |
| `UpdateCompanyBilling`     | Saves the tax numbers and billing address. Returns false when `profile_managed_by_company`                                                                                                                                                                                    |
| `ReactivateCompany`        | Suspended → Active. Logs `company.reactivated`                                                                                                                                                                                                                                |
| `UpdateReservationCompany` | Sets or clears `reservations.company_id` (module 12 folder). Refuses `reservation_closed`, `company_not_available`, `master_has_lines`. Points an empty Master folio's payer at the company, runs `SyncRoutingRules`, logs `reservation.company_updated` with old and new ids |

Suspend and reactivate return `false` when the company is already in the target status; the controller shows an error toast.

## Permissions

| Permission       | Guards                                                       | Default roles                       |
| ---------------- | ------------------------------------------------------------ | ----------------------------------- |
| `company.view`   | Companies list and company page, pick a company on a booking | Owner, Manager, Front desk, Finance |
| `company.manage` | Create, edit, suspend, reactivate                            | Owner, Manager, Finance             |

## Pages

| Page                      | Route                                                                                 | Permission                                  |
| ------------------------- | ------------------------------------------------------------------------------------- | ------------------------------------------- |
| Companies list            | `GET /p/{code}/finance/city-ledger/companies` (`finance.city-ledger.companies.index`) | `company.view`                              |
| Add company               | `POST .../companies` (Sheet on the list)                                              | `company.manage`                            |
| Company page, Details tab | `GET .../companies/{company}`, saved with `PUT .../companies/{company}/details`       | `company.view`, save needs `company.manage` |
| Company page, Credit tab  | `GET .../companies/{company}/credit`, saved with `PUT .../companies/{company}/credit` | `company.view`, save needs `company.manage` |
| Tax and billing form      | `PUT .../companies/{company}/billing` (Details tab)                                   | `company.manage`                            |
| Suspend                   | `POST .../companies/{company}/suspend` (AlertDialog)                                  | `company.manage`                            |
| Reactivate                | `POST .../companies/{company}/reactivate`                                             | `company.manage`                            |

```
Finance › City ledger › Companies                     [Add company]
 Name             Type          Credit limit   Terms   Status
 Borneo Travel    Travel agent  RM 20,000      30 d    Active
 Petronas Miri    Corporate     RM 50,000      45 d    Active
 JKR Sabah        Government    —              60 d    Suspended
```

- **Add company** is a Sheet with 7 fields in two sections: Company (name, type, contact email, contact phone) and Credit (credit limit, payment terms, direct billing, on by default). After saving, the company page opens; tax numbers and the billing address are added there.
- The company page follows the Settings pattern: each tab is a link with its own URL, and each section is one form saved on its own. **Details tab:** a Details form (name, type, contact email and phone), then a **Tax numbers and billing address** form (BRN, TIN, SST, address lines, city, postcode, state, country). When the company manages them, that second section is read-only with the address in a read-only text area. **Credit tab:** credit limit (empty means no limit), payment terms, direct billing. Later modules add Rates, Portal and Statement tabs.
- Staff with only `company.view` see the same fields disabled, with no Save button.
- Another property's company returns 404 (scoped binding).
- The sidebar shows **City ledger › Companies** in the Finance context for users with `company.view`.

## Bulk add

A full page for loading many companies at once: typed into a grid or read from a CSV or XLSX file. Built like [Staff bulk add](../03-staff/03-temp-passwords.md#bulk-add-full-page).

```
Finance › City ledger › Companies › Bulk add
[ Simple | Detailed ]        [Upload CSV / XLSX]  [Template ▾ CSV · XLSX]
Simple:   # │ Name │ Type │ Email │ Phone │ Credit limit │ Terms │ Direct billing │ ✕
Detailed: the Simple columns, then BRN │ TIN │ SST │ Address 1 │ Address 2 │ City │ Postcode │ State │ Country
                                                    [Cancel]  [Create 3 companies]
```

| Part         | Rule                                                                                                                                                                                                                                                                                                                             |
| ------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Entry        | The list's button is a menu: **Add company ▾** → Add one (the sheet) / Bulk add                                                                                                                                                                                                                                                  |
| Toggle       | Simple (the 7 add-sheet fields) or Detailed (all 16). Browser only, remembered per browser. Hiding columns keeps their values; they are still saved, and a note says how many rows have hidden values                                                                                                                            |
| File columns | `name`, `type`, `email`, `phone`, `credit_limit`, `terms_days`, `direct_billing`; Detailed adds `brn`, `tin`, `sst`, `address_line1`, `address_line2`, `city`, `postcode`, `state`, `country`. Headers match ignoring case; only `name` is required. A file with any Detailed column switches the page to Detailed               |
| File values  | Type by label or value (`Travel agent`, `travel_agent`). Direct billing `yes`/`no` (empty means yes). Country by code or name. A value that cannot be read is left empty with a problem on its row                                                                                                                               |
| Template     | `GET .../companies/import-template.{csv                                                                                                                                                                                                                                                                                          | xlsx}?mode=simple | detailed`: the header row of the chosen mode |
| Save         | Each row follows the add sheet and tax/address rules. Names are unique ignoring case, within the list and against existing companies. Up to 200 rows. All rows or none; errors point at their row (`rows.3.name`). `BulkCreateCompanies` runs `CreateCompany` per row in one transaction, so each company logs `company.created` |
| After saving | Back to the list with "3 companies added."                                                                                                                                                                                                                                                                                       |
| Permission   | `company.manage` for every bulk route                                                                                                                                                                                                                                                                                            |

## Lessons from wastays

`corporate_accounts` + `hotel_corporate_accounts` (39 columns, including agent booking and payment holds) become one slim table.

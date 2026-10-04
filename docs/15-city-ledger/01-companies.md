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
- `credit_limit` is nullable (no limit). `payment_terms_days` is nullable. `direct_bill_enabled` defaults to false. Credit holds come in [Credit control](04-credit-control-and-reports.md).
- `reservations.company_id` and `folios.payer_company_id` get foreign keys to `companies` (restrict on delete).

## Rules

- `reservations.company_id` records the billed company.
- `folios.payer_company_id` makes a company pay a folio.
- The name is unique per property, ignoring case, so one company never splits into two ledgers.
- Companies are never deleted. Invoices, folios and reservations point at them. Suspend instead.
- A Suspended company cannot be picked on a new booking or set on a reservation. Existing reservations keep it.
- Changing `reservations.company_id` is refused while the Master folio holds lines: "Move or void the master folio's charges first." Without lines, the Master payer follows the new company (see [Routing](../13-folio/03-routing.md#known-later-work)).
- `company_rate_plans` links negotiated derived rate plans. The booking form offers them when the company is selected.
- A plan linked to any company is hidden on the booking form until one of those companies is selected. This stops staff giving company prices to walk-ins.
- `portal_enabled`, `portal_cancel_cutoff_days` and `company_users` belong to the [Company portal](../23-company-portal/01-company-portal.md). They are edited on the company's Portal tab, with its company users and invitations.

## Actions

| Action              | Does                                                 |
| ------------------- | ---------------------------------------------------- |
| `CreateCompany`     | Creates an Active company. Logs `company.created`    |
| `UpdateCompany`     | Updates the fields. Logs `company.updated` with diff |
| `SuspendCompany`    | Active → Suspended. Logs `company.suspended`         |
| `ReactivateCompany` | Suspended → Active. Logs `company.reactivated`       |

Suspend and reactivate return `false` when the company is already in the target status; the controller shows an error toast.

## Permissions

| Permission       | Guards                                                       | Default roles                       |
| ---------------- | ------------------------------------------------------------ | ----------------------------------- |
| `company.view`   | Companies list and company page, pick a company on a booking | Owner, Manager, Front desk, Finance |
| `company.manage` | Create, edit, suspend, reactivate                            | Owner, Manager, Finance             |

## Pages

| Page           | Route                                                                                 | Permission       |
| -------------- | ------------------------------------------------------------------------------------- | ---------------- |
| Companies list | `GET /p/{code}/finance/city-ledger/companies` (`finance.city-ledger.companies.index`) | `company.view`   |
| Add company    | `GET .../companies/create`, `POST .../companies`                                      | `company.manage` |
| Company page   | `GET .../companies/{company}` (Details tab; later Rates, Portal, Statement)           | `company.view`   |
| Edit company   | `GET .../companies/{company}/edit`, `PUT .../companies/{company}`                     | `company.manage` |
| Suspend        | `POST .../companies/{company}/suspend` (AlertDialog)                                  | `company.manage` |
| Reactivate     | `POST .../companies/{company}/reactivate`                                             | `company.manage` |

```
Finance › City ledger › Companies                     [Add company]
 Name             Type          Credit limit   Terms   Status
 Borneo Travel    Travel agent  RM 20,000      30 d    Active
 Petronas Miri    Corporate     RM 50,000      45 d    Active
 JKR Sabah        Government    —              60 d    Suspended
```

- The add and edit form is a full page (about 16 fields) with three sections: Identity, Billing address, Credit.
- Another property's company returns 404 (scoped binding).
- The sidebar shows **City ledger › Companies** in the Finance context for users with `company.view`.

## Lessons from wastays

`corporate_accounts` + `hotel_corporate_accounts` (39 columns, including agent booking and payment holds) become one slim table.

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
company_rate_plans   company_id, rate_plan_id
company_users        see Company portal
```

## Enums

- `CompanyType`: Corporate, TravelAgent, Government.
- `CompanyStatus`: Active, Suspended.

## Rules

- `reservations.company_id` records the billed company.
- `folios.payer_company_id` makes a company pay a folio.
- Changing `reservations.company_id` after the Master folio exists must decide what happens to the Master payer (see [Routing](../13-folio/03-routing.md#known-later-work)).
- `company_rate_plans` links negotiated derived rate plans. The booking form offers them when the company is selected.
- A plan linked to any company is hidden on the booking form until one of those companies is selected. This stops staff giving company prices to walk-ins.
- `portal_enabled`, `portal_cancel_cutoff_days` and `company_users` belong to the [Company portal](../23-company-portal/01-company-portal.md). They are edited on the company's Portal tab, with its company users and invitations.

## Actions

`CreateCompany`, `UpdateCompany`, `SuspendCompany`.

## Permissions

`company.manage`.

## Lessons from wastays

`corporate_accounts` + `hotel_corporate_accounts` (39 columns, including agent booking and payment holds) become one slim table.

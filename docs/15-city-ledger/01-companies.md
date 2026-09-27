# Companies

## Purpose

Companies, travel agents and government bodies that stay now and pay later. Hotels call this the **city ledger**; the UI uses that name. Tables keep the standard `ar_*` names.

## Data model

```
companies            id, property_id, name, type (CompanyType), brn, tin, sst_registration_number,
                     contact_email, contact_phone,
                     billing_address_line1, billing_address_line2, billing_city,
                     billing_state_code, billing_postal_code, billing_country_code,
                     credit_limit, payment_terms_days, direct_bill_enabled,
                     status (CompanyStatus), timestamps
company_rate_plans   company_id, rate_plan_id
```

## Enums

- `CompanyType`: Company, TravelAgent, Government.
- `CompanyStatus`: Active, Suspended.

## Rules

- `reservations.company_id` records the billed company.
- `folios.payer_company_id` makes a company pay a folio.
- `company_rate_plans` links negotiated derived rate plans. The booking form offers them when the company is selected.

## Actions

`CreateCompany`, `UpdateCompany`, `SuspendCompany`.

## Permissions

`company.manage`.

## Lessons from wastays

`corporate_accounts` + `hotel_corporate_accounts` (39 columns, including agent booking and payment holds) become one slim table.

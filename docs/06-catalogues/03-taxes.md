# Taxes

## Purpose

Configure taxes once and apply them to the right charges automatically.

## Data model

```
taxes                   id, property_id, transaction_code_id, name, code,
                        rate_type (TaxRateType), rate, is_inclusive,
                        foreign_guests_only, collect_at_check_in, registration_number, enabled, timestamps
tax_transaction_code    tax_id, transaction_code_id
tax_base_taxes          tax_id, base_tax_id
                        unique (tax_id, base_tax_id), check tax_id <> base_tax_id
```

Names and short codes are unique within a property, ignoring case. A custom tax creates and owns an active posting transaction code named `TAX_{SHORT_CODE}`. Disabling the tax does not deactivate that accounting identity.

## Enums

`TaxRateType`: Percent, Flat.

`tax_base_taxes` says which other taxes a percentage tax is calculated on. See [Tax base](#tax-base). Both taxes belong to the same property. Deleting either tax deletes the row.

## Services

`TaxCalculator` accepts a property, source charge code, entered amount, quantity and guest nationality. It returns the net charge, ordered tax components and gross amount without depending on folio tables. `calculateWithTaxes()` takes an explicit set of the property's taxes instead of the linked ones, for previews of unsaved selections.

- Flat taxes are `rate × quantity`.
- A percentage tax is calculated on its base: the net amount plus the amounts of its base taxes that are also in the calculation. With no base taxes, the base is the net amount, as before.
- Taxes are calculated in **calculation order**: a tax comes after every tax in its base; ties go by tax ID.
- Inclusive flat taxes are removed first. Inclusive percentage taxes are then extracted together from the remaining gross amount, with each tax's effective rate on the net (see [Included taxes with a base](#included-taxes-with-a-base)).
- Exclusive taxes are then calculated in calculation order on the resulting net amount plus their base taxes.
- Each tax amount is rounded before a later tax uses it, so the base on the folio adds up.
- Rounding uses the property currency. Any inclusive rounding remainder goes to the last inclusive percentage tax in calculation order.
- Each `CalculatedTax` carries its `baseAmount` (null for a flat tax). Previews show it as "on 110.00", and posting stores it on the tax line.
- A foreign-only tax refuses calculation when nationality is missing. Inclusive flat taxes refuse calculation when they exceed the entered amount.

## Rules

- The tax form only describes the tax. It does not choose charges.
- The pivot says which charge codes each tax applies to. Two screens edit it: [Room revenue](06-room-revenue.md) for the five room codes, and [Charge items](02-charge-items.md#taxes) for each item. Saving a tax never changes the pivot.
- An enabled tax may apply to no charges yet. The tax list shows an **Applies to** summary ("Room revenue, Breakfast, Parking") and a "Not applied to any charge" badge when it is empty.
- Tax lines are children of the charge line (`parent_line_id`), also when the tax is calculated on another tax. Reversing a charge reverses its taxes.
- `collect_at_check_in` (default false) marks a tax that staff ask for when a guest checks in. It does not change posting: the tax is still a child line of each night's room line. It decides which taxes the [check-in form](../18-front-desk/01-check-in-and-check-out.md#collect-now) adds up as one amount to collect. The tax form has a **Collect at check-in** switch.
- Malaysia presets are seeded disabled and apply to all five room revenue codes: 8% exclusive Service Tax for all guests and RM10 exclusive Tourism tax per room-night for foreign guests, with **Collect at check-in** on. The owner confirms them in onboarding.
- Properties outside Malaysia get no tax presets. They add their own taxes. "Foreign guests only" compares the guest's nationality with the property's country.

## Tax base

Some taxes are charged on the price plus other taxes, for example a sales tax on the room price plus a service charge. The rule belongs to the tax, not to each charge, so it is set once on the tax form and every charge follows it. It is country-neutral: no tax name or country is built in.

| Shape                                     | Setup                                       |
| ----------------------------------------- | ------------------------------------------- |
| Tax on the price only (the default)       | No base taxes                               |
| Service charge, then a tax on both        | Tax → base: Service charge                  |
| Two fees, then a tax on all of them       | Tax → base: Fee A, Fee B                    |
| A tax on another tax                      | Tax 2 → base: Tax 1                         |
| A chain                                   | Tax C → base: Tax B; Tax B → base: Tax A    |
| A per-night flat fee that is taxed or not | Add it to the other tax's base, or leave it |

Example with Service charge 10%, City fee 3.60 flat, Sales tax 8% on Service charge and City fee, and Levy 1% on Sales tax, for a 100.00 room night:

```
Room                 100.00
Service charge 10%    10.00   on 100.00
City fee               3.60
Sales tax 8%           9.09   on 113.60
Levy 1%                1.09   on 109.09
Total                123.78
```

Rules:

- Only a **percentage** tax has a base. A flat tax is always `rate × quantity`, but it can be in another tax's base.
- A base tax counts only when it is also in the calculation for that charge: it applies to the charge code, it is enabled and it is not skipped for the guest's nationality. Otherwise it is left out and the tax is calculated on the rest.
- Chains are allowed. Saving refuses a loop (`tax_base_cycle`): a tax cannot be in its own base, directly or through other taxes.
- An **included** tax can only have included taxes in its base (`tax_base_inclusive_mismatch`). An added tax can have both. This keeps the reverse calculation exact.
- Changing a tax's base never changes posted lines. Charges keep the taxes and bases they were posted with.
- Country profiles may seed base links. `GenericProfile` seeds none. `MalaysiaProfile` seeds none until the rule is confirmed: whether service tax is charged on a service charge and on a local council fee (to verify with the RMCD accommodation guide).

### Included taxes with a base

With included percentage taxes, the calculator works out each tax's effective rate on the net amount in calculation order: `effective(tax) = rate × (1 + sum of the effective rates of its percentage base taxes)`, plus `rate × flat` for included flat base taxes. The net is `(gross − included flat taxes − the flat parts) ÷ (1 + sum of effective rates)`. Each tax is then calculated on its base and rounded as above. Inclusive flat taxes still refuse when they exceed the entered amount.

### Tax form

The **Calculated on** section sits under **In price**. A flat rate shows "Charged per unit of the charge's quantity" instead.

```
┌ Edit tax ───────────────────────────────────────── ✕ ┐
│ Name          [ Sales tax                          ] │
│ Short code    [ SALES                              ] │
│ Rate          [ 8.00 ][ % ⌄ ]                        │
│ In price      [ Added on top ⌄ ]                     │
│ Reg. no.      [                                    ] │
│ ──────────────────────────────────────────────────── │
│ Calculated on                                        │
│   Price  +  [Service charge ✕][City fee ✕]       ⌄   │
│   Sales tax is 8.00% of the price plus these taxes,  │
│   when they apply to the same charge.                │
│ ──────────────────────────────────────────────────── │
│ [○] Foreign guests only                              │
│ [○] Collect at check-in                              │
│ Enabled       [● on ]                                │
├──────────────────────────────────────────────────────┤
│                               [ Cancel ]  [ Save ]   │
└──────────────────────────────────────────────────────┘
```

- The multiselect (`MultiCombobox`) offers the property's other taxes, disabled ones marked "Off". A tax that would make a loop is disabled with the reason, for example "Levy is calculated on Sales tax".
- The help text uses the tax's own name and rate. No tax name is hard-coded.
- `TaxRequest` validates `base_tax_ids` (same property, not itself, no loop, the inclusive rule). `CreateTax` and `UpdateTax` sync `tax_base_taxes` in the same transaction and log the base ids.
- The tax list gets a **Calculated on** column: "Price" or "Price + Service charge, City fee" for a percentage tax, "Per night" or "Per unit" for a flat tax.

```
┌──────────────────┬──────────┬───────────┬───────────────────────────┬────────────────────────┐
│ Name             │ Rate     │ In price  │ Calculated on             │ Applies to             │
├──────────────────┼──────────┼───────────┼───────────────────────────┼────────────────────────┤
│ Service charge   │ 10.00%   │ Added     │ Price                     │ Room revenue, Breakfast│
│ City fee         │ 3.60     │ Added     │ Per unit                  │ Room revenue           │
│ Sales tax        │ 8.00%    │ Added     │ Price + Service charge,   │ Room revenue, Breakfast│
│                  │          │           │ City fee                  │                        │
│ Levy             │ 1.00%    │ Added     │ Price + Sales tax         │ Room revenue           │
└──────────────────┴──────────┴───────────┴───────────────────────────┴────────────────────────┘
```

### Decision

Replaces the earlier rule "Taxes never compound on other taxes" (decided 2026-10-30). A tax with no base taxes still works as before, so existing properties see no change until they link taxes.

## Lessons from wastays

Tax data was on `hotels` columns and in `hotel_taxes`, linked by a string `primary_tax_key`. One table plus a pivot replaces both.

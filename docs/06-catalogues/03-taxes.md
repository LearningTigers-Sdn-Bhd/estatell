# Taxes

## Purpose

Configure taxes once and apply them to the right charges automatically.

## Data model

```
taxes                   id, property_id, transaction_code_id, name, code,
                        rate_type (TaxRateType), rate, is_inclusive,
                        foreign_guests_only, collect_at_check_in, registration_number, enabled, timestamps
tax_transaction_code    tax_id, transaction_code_id
reservation_unit_tax_waivers
                        id, property_id, reservation_unit_id (cascade), tax_id (restrict),
                        reason (TaxWaiverReason), paid_to (string 60, nullable), reference (string 60),
                        user_id, timestamps
                        UNIQUE (reservation_unit_id, tax_id)
                        CHECK (reason = 'paid_elsewhere') = (paid_to IS NOT NULL)
```

Names and short codes are unique within a property, ignoring case. A custom tax creates and owns an active posting transaction code named `TAX_{SHORT_CODE}`. Disabling the tax does not deactivate that accounting identity.

## Enums

`TaxRateType`: Percent, Flat.

`TaxWaiverReason`: PaidElsewhere ("OTA or agent"), GuestReceipt ("Guest receipt"). Each has a `description()` shown on its choice card.

## Services

`TaxCalculator` accepts a property, source charge code, entered amount, quantity and guest nationality. It returns the net charge, ordered tax components and gross amount without depending on folio tables. `calculateWithTaxes()` takes an explicit set of the property's taxes instead of the linked ones, for previews of unsaved selections.

- Flat taxes are `rate × quantity`.
- Inclusive flat taxes are removed first. Inclusive percentage taxes are then extracted together from the remaining gross amount.
- Exclusive taxes use the resulting net amount. Taxes never compound on other taxes.
- Rounding uses the property currency. Any inclusive rounding remainder goes to the last percentage tax in tax-ID order.
- A foreign-only tax refuses calculation when nationality is missing. Inclusive flat taxes refuse calculation when they exceed the entered amount.

## Rules

- The tax form only describes the tax. It does not choose charges.
- The pivot says which charge codes each tax applies to. Two screens edit it: [Room revenue](06-room-revenue.md) for the five room codes, and [Charge items](02-charge-items.md#taxes) for each item. Saving a tax never changes the pivot.
- An enabled tax may apply to no charges yet. The tax list shows an **Applies to** summary ("Room revenue, Breakfast, Parking") and a "Not applied to any charge" badge when it is empty.
- Tax lines are children of the charge line (`parent_line_id`). Reversing a charge reverses its taxes.
- `collect_at_check_in` (default false) marks a tax that staff ask for when a guest checks in. The tax is still a child line of each night's room line. When a company pays the room and not this tax, it posts on the guest's unit folio instead (see [Collected taxes](../13-folio/03-routing.md#collected-taxes)). It decides which taxes the [check-in form](../18-front-desk/01-check-in-and-check-out.md#collect-now) adds up as one amount to collect. The tax form has a **Collect at check-in** switch.
- Malaysia presets are seeded disabled and apply to all five room revenue codes: 8% exclusive Service Tax for all guests and RM10 exclusive Tourism tax per room-night for foreign guests, with **Collect at check-in** on. The owner confirms them in onboarding.
- Properties outside Malaysia get no tax presets. They add their own taxes. "Foreign guests only" compares the guest's nationality with the property's country.

## Tax waivers

Some OTAs collect a tax themselves on some bookings and not others. Trip.com, for example, collects the Malaysian tourism tax on some prepaid bookings and asks the hotel to collect it on others. The **Collect at check-in** switch is one setting for the whole tax, so it cannot tell these bookings apart. A waiver stops one tax for one unit instead.

- **Who:** `folio.waive-tax` ("Waive taxes paid elsewhere"): Owner, Manager, Front desk and Finance by default.
- **Where:** the unit ⋯ menu on the reservation page, **Taxes paid elsewhere…** (`UnitAction::WaiveTax`), for a Confirmed, Checked-in or Checked-out unit. The Dialog lists the taxes already waived, each with **Remove**, and a form: the taxes (the property's enabled taxes not waived yet), Paid by (the reason), **Paid to** (only for PaidElsewhere; starts with the OTA booking source name or the company name) and the reference (starts with the booking's External reference). The unit panel shows a **Tax** row: "Tourism tax: Paid by Trip.com · 1128152109837544".
- **Several rooms at once:** **More** › **Taxes paid elsewhere…** on the reservation page, shown with `folio.waive-tax` while a room can take a waiver. The same fields plus **Rooms** (`MultiSelectWithAll`, every room that can take a waiver picked by default; hidden on a one-room reservation). Its options come from the optional prop `taxWaiverOptions` (the property's enabled taxes and the same starting values). `WaiveGroupTax` (`POST …/reservations/{reservation}/tax-waivers`) runs `WaiveUnitTax` for each room with the taxes it does not have yet and skips a room that has them all. A refusal of any room rolls every room back. Rooms of another reservation or that cannot take a waiver are `unit_not_waivable`; nothing left to waive is `already_waived`. The toast says "Tax waived for 3 rooms. 1 already had it." **Remove** stays on each room's own Dialog.
- **Waive** (`WaiveUnitTax`): one row per tax. The live tax lines of those taxes on the unit's stay charges (its nights, their inclusions and Change price corrections, and its day-use block) are reversed on the business date, in one manual batch per folio with the reason "Tax paid by Trip.com · {reference}" or "Tax paid by guest · receipt {reference}". Add-ons and fees keep their tax. Refusals, which write nothing: `unit_not_waivable`, `tax_not_found` (not a tax of the property), `already_waived` (also stops a repeated click), `folio_not_open`, `no_business_date`.
- **Nights not posted yet:** `TaxWaivers` is the one rule. `NightChargeQuote` and `DayUseChargeQuote` drop the waived taxes, so the night audit, the Scheduled rows, the Collect now total and Change price all agree.
- **Remove** (`RemoveUnitTaxWaiver`): deletes the row. Only nights not posted yet get the tax back. The reversed lines stay reversed; staff use Add tax on a posted night. A second Remove is `waiver_not_found`.
- **Logged** on the reservation: `reservation.unit_tax_waived` (tax ids, reason, payer, reference, reversed line ids) and `reservation.unit_tax_waiver_removed`.
- **On documents:** `TaxWaiverNotes` gives one note per distinct waiver of the units, "Tourism tax paid by Trip.com (ref 1128152109837544)." or "Tourism tax paid by the guest (receipt TTX-88231).". The [booking summary](../12-reservations/08-booking-summary.md#charges) prints them under the charges (only the print with prices, so the reservation page reads no waivers), and the [folio invoice](../13-folio/06-folio-invoice.md#content) prints those of the units on the folio.
- **On the report:** the [non-national guests report](../22-reports/01-core-reports.md) has a **Paid elsewhere** column ("Trip.com · 1128…", "Guest receipt · TTX-88231") for waived foreign-guest taxes, so the hotel does not declare tax an OTA declares.
- **Not yet:** a reminder in check-in Collect now for OTA bookings (later). Guest and company exemptions can add their own reasons to the same table.

## Lessons from wastays

Tax data was on `hotels` columns and in `hotel_taxes`, linked by a string `primary_tax_key`. One table plus a pivot replaces both.

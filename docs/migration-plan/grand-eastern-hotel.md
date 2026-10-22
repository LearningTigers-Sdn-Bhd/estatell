# Grand Eastern Hotel

wastays id 3 · Kota Kinabalu · 78 rooms · in wastays since 13 Aug 2026 · prefix `GEH` · **Full replay from 13 Aug, pilot**

## Summary

The cleanest data in wastays. Every closed folio is at zero, every in-house guest has paid in advance, and the hotel has no companies or future bookings. It is the pilot: it tests the importer on real data with little money at risk.

The hotel wants all its history in estatell, so Grand Eastern does not use the usual cutover. estatell goes live on **13 Aug 2026**, the first wastays business date, and the importer replays every wastays day through estatell Actions and the night audit. See [Go live](../17-onboarding/03-go-live.md#actions) and [Migration from another PMS](../17-onboarding/07-migration.md#the-rule).

## Setup

| Item          | wastays                                                                                                                                 | estatell                                                                      |
| ------------- | --------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| Room types    | 6: Superior Deluxe Twin 25, Superior Deluxe Family 6, Superior Triple 2, Super Deluxe Queen 10, Deluxe Queen 8, Superior Deluxe King 27 | Copy. Max children is 0 on 5 types: confirm                                   |
| Rooms         | 78, all active                                                                                                                          | Copy                                                                          |
| Rate plans    | 18: Standard, Walk-in and Corporate, repeated for each room type                                                                        | 3 plans, see below                                                            |
| Taxes         | SST on. DBKK 1.30 flat, off                                                                                                             | Service Tax 8%. DBKK: ask the hotel                                           |
| Extra charges | Cleaning Fee, Damage Charge, Food & Beverage, Miscellaneous Revenue, Parking, Extra Bed, TTX. All priced by hand                        | Charge items. TTX is likely the tourism tax: map it to the Tourism tax preset |

### Rate plans

| estatell plan  | Type                       | Price                                                                                    | Who can use it |
| -------------- | -------------------------- | ---------------------------------------------------------------------------------------- | -------------- |
| Standard Rate  | PerRoom                    | Twin 150.00, Family 178.00, Triple 178.00, Super Queen 133.00, Queen 128.00, King 150.00 | Everyone       |
| Walk-in Rate   | Derived from Standard, +0% | Follows Standard                                                                         | Everyone       |
| Corporate Rate | Derived from Standard, +0% | Follows Standard                                                                         | All companies  |

The wastays base prices (162.00, 192.24, 143.64, 138.24) include SST: 162.00 posts as 150.00 plus 12.00 SST, and every base ÷ 1.08 is a whole ringgit. estatell stores the net price with SST 8% exclusive. Confirm before import (see [Tax basis](README.md#rate-plan-mapping)). Staff often type their own price at the desk (103.27, 117.05, 133.00 are common), so the daily rate matters less here than the folio history.

## History

| Item            | Count                                                          | In the replay                               |
| --------------- | -------------------------------------------------------------- | ------------------------------------------- |
| Business dates  | 70 audits, 13 Aug to 21 Oct 2026, all completed                | One estatell audit per date                 |
| Bookings        | 686 (check-in 14 Aug to check-out 24 Oct 2026)                 | Normal reservations, created on their day   |
| Booking sources | Walk-in 351, Booking.com 205, Agoda 110, phone 18, Traveloka 2 | Kept on the reservation                     |
| Guests          | Part of 743 across wastays                                     | Guest profiles, through the Rails export    |
| Folio lines     | 2,589                                                          | Posted on the same business date as wastays |
| Invoices        | 670, `GEH-26700001` to `GEH-26700670`                          | Same numbers                                |
| Receipts        | 710, `GEH-26500001` to `GEH-26500710`, all issued              | Same numbers                                |

Figures are from the 22 Oct dump. They grow until cutover.

## Replay

### Day loop

`ApproveGoLive` opens the first business date on 13 Aug 2026. The importer then goes through every wastays business date in order:

```
for each wastays business date, 13 Aug → the last wastays audit:
  1. create the bookings made that day
  2. check in the arrivals, check out the departures
  3. post the extra charges and payments wastays posted that day
  4. RunNightAudit → the next date opens
```

- Every write goes through estatell Actions, never direct inserts. The wastays id is the idempotency key, and the audit skips steps already completed, so a stopped run resumes.
- Each night's price is fixed to the amount wastays posted, through `unit_nights.override_amount`. The room charge step then posts the wastays amount, typed desk prices included. Nothing is recalculated.
- The audit runs for past dates without force: `business_date_not_ended` refuses only a date that has not ended.
- The replay posts normal lines. It needs no bridge: no migrated stays, no opening balances, no Old system payments, no Old stays and no copied daily figures. The audit builds the daily figures.
- After the last wastays audit, estatell opens the next date, which is the cutover day. Staff start work in estatell on that day.

### Document numbers

estatell builds the same prefix (`GEH`) and the same reference format as wastays. Guests already hold wastays invoices and receipts, so each replayed document keeps its wastays number: invoice `GEH-26700123` in estatell is the same stay as in wastays.

| Document          | wastays               | In the replay                                                                      |
| ----------------- | --------------------- | ---------------------------------------------------------------------------------- |
| Reservation       | `GEH-261xxxxx`        | Same number                                                                        |
| Registration card | `GEH-262xxxxx`        | Same number                                                                        |
| Receipt, invoice  | `GEH-265…`, `-267…`   | Same number                                                                        |
| Folio             | Plain number, 1, 2, 3 | estatell numbers it (`GEH-263xxxxx`). The wastays number is kept in `import_links` |

`NextDocumentNumber` takes only a floor today. The importer passes a planned number, and the sequence refuses one that is not above `last_number`. The desk flow does not change.

### Settings

| Setting                 | Value                                                                                                                  |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `migration_window_days` | 90. The window then closes on 11 Nov 2026. Raise it if cutover is later and a bridge is still needed for a stray item. |
| E-invoice               | Not used by the hotel. Nothing to switch off.                                                                          |

### Tax reports

Replayed lines are normal postings, so estatell's tax report shows full SST (and tourism tax, if TTX is that tax) for August to October. The hotel filed earlier periods from wastays reports.

| Period                                         | System of record                                                   |
| ---------------------------------------------- | ------------------------------------------------------------------ |
| Periods already filed                          | wastays. Never refile them from estatell, never add both together. |
| Periods not filed yet, including replayed days | estatell, after the dry run shows it matches wastays.              |

Tell the hotel's accountant before cutover.

### Dry run

1. Export a bundle from wastays prod (read only) and fetch it into a copy of the estatell database.
2. Replay all days. Each day is compared with its wastays night audit: room revenue, other revenue, SST, tourism tax, payments, occupancy, and every document number and amount.
3. Fix and repeat until every day matches. Only then run on prod.

## Importer

No database connection between the apps. wastays exports a zip bundle, R2 carries it, and estatell reads it. CLI only, no UI. Both apps run on Coolify, so the commands run in each app's Coolify terminal.

```
wastays (Coolify)                     R2 (existing buckets)                estatell (Coolify)
bin/rails "estatell:export[3]"  ───▶  wastays-bucket
  read only, guests decrypted           exports/grand-eastern-hotel/<date>.zip
  CSV per table + manifest.json
  zip + SHA-256                       copy in the R2 dashboard to the
                                      estatell-production
                                        imports/grand-eastern-hotel/<date>.zip ───▶  wastays:fetch {key}
                                                                               wastays:import-setup
                                                                               ApproveGoLive (13 Aug)
                                                                               wastays:import-guests
                                                                               wastays:replay
                                                                               wastays:purge
```

- The export uploads through the wastays Active Storage R2 service, and `wastays:fetch` reads the existing `r2` disk. No new bucket, token or env value. Both buckets are in the same Cloudflare account: download the zip from `wastays-bucket` and drag it into `estatell-production` at `imports/grand-eastern-hotel/`.
- The zip holds decrypted guest data. After cutover, delete it from both buckets and from the laptop that copied it. `wastays:purge` removes only the estatell copies.
- `manifest.json` holds the hotel id, the last audit date, the row count of each file and the checksum. `wastays:fetch` refuses a bundle that does not match it.
- `import_links` (property, source table, source id, target type, target id, unique) records every wastays row already written, so a stopped run resumes and posts nothing twice.
- `wastays:replay {bundle} --until= --dry-run` refuses while a Channex channel is connected, and refuses a day when the estatell business date is not the wastays date. The first day that does not match stops the run with a table of the differences and a non-zero exit.
- Replayed lines carry the replay clock time. The activity log marks them "replay".
- Code lives in `app/Actions/Onboarding/Wastays/` and `app/Services/Onboarding/Wastays/`.

### Bundle format

The zip root holds `manifest.json` and one `{table}.csv` per table. No folders. The CSV header is the column names. An empty value is null.

```json
{
    "format_version": 1,
    "hotel_id": 3,
    "hotel_code": "GEH",
    "hotel_name": "Grand Eastern Hotel",
    "exported_at": "2026-10-22T10:15:00+08:00",
    "first_audit_date": "2026-08-13",
    "last_audit_date": "2026-10-21",
    "files": { "bookings.csv": { "rows": 1234, "sha256": "<hex>" } }
}
```

`wastays:fetch {key} {--sha256=}` downloads the zip to `storage/app/private/imports/{hotel-slug}/{date}/` and checks:

1. The zip SHA-256, when `--sha256` is given or a `{key}.sha256` object sits next to the zip.
2. `format_version` is 1.
3. Every listed file exists, its SHA-256 matches, and its data row count matches.
4. No CSV is present that the manifest does not list.

On any mismatch it deletes what it extracted, prints why and exits non-zero. On success it prints the bundle path, hotel, audit date range and row counts. `WastaysBundle::rows($table)` reads a table as a lazy collection of associative arrays.

### Mapping

| wastays                                                | estatell                                                                     |
| ------------------------------------------------------ | ---------------------------------------------------------------------------- |
| Night amount                                           | `override_amount` = the wastays net amount, SST 8% exclusive (110.32 + 8.83) |
| `gateway_payment` (all 108 GEH lines are Card Payment) | The payment method named by the line's transaction code, here Card           |
| TTX                                                    | The Tourism tax preset, once the hotel confirms                              |
| Corrections, moved lines, early departures             | `ReverseLine`, `MoveCharges`, the early check-out                            |
| Tourism tax voucher references                         | Not carried over. estatell has no voucher number                             |

### Both import commands

`wastays:import-setup` and `wastays:import-guests` take the same arguments: `{bundle} {property} --actor= --dry-run`.

- `{bundle}` is the extracted bundle path that `wastays:fetch` prints. `{property}` is the estatell property code. The property is created in the admin first, so it already has its seeded defaults.
- `--actor` is the email of an admin user (`is_admin`). The activity log shows that user.
- Each command refuses, and writes nothing, when the bundle's `hotel_code` is not the property's document prefix, or when go-live is already approved.
- Every write goes through Actions in one transaction. `--dry-run` runs everything, rolls back and prints the summary.
- Every written wastays row gets an `import_links` row, so a second run skips it.
- The output shows counts only, never names, emails, phones or ID numbers.

### Setup import

`wastays:import-setup` writes the [Setup](#setup):

| Part              | From wastays                                    | In estatell                                                                                                                                                                      |
| ----------------- | ----------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Room types        | `room_types`                                    | `CreateRoomType`, sold by room. Name in Title Case. Max adults and max children as in wastays. Base occupancy 2, or max adults when lower                                        |
| Rooms             | `rooms`                                         | `CreateRoom`, same number and type. No room groups                                                                                                                               |
| Rate plans        | `rate_plans`                                    | The seeded Basic Rate is renamed Standard Rate. Walk-in Rate and Corporate Rate are derived from it at +0% (`CreateDerivedRatePlan`). Corporate is open to all companies         |
| Rates             | `room_types.base_price`                         | `SaveRates` on Standard Rate from the first audit date to 31 Dec 2027, at base ÷ 1.08 rounded to 2 decimals                                                                      |
| SST               | `hotels.sst_enabled`, `sst_registration_number` | The seeded SST tax is turned on: 8%, exclusive, on room revenue, with the registration number                                                                                    |
| Tourism tax       | `TAX_TTX` and the manual `TTX` charge           | The seeded Tourism tax is turned on (RM 10, foreign guests only)                                                                                                                 |
| DBKK              | `hotel_taxes` DBKK, off, no lines               | Skipped                                                                                                                                                                          |
| Charge items      | `hotel_extra_charges`                           | Food & Beverage → the starter item. Damage Charge → the built-in Damage item. Cleaning Fee, Miscellaneous Revenue, Parking and Extra Bed → new items, no price. Parking inactive |
| Payments          | `hotel_payment_methods`                         | Cash, Cash - Prepayment, Cash Prepayment → Cash. Card, Card Prepayment, Gateway Manual Recovery → Card. Bank Transfer → Bank transfer. OTA Collected → OTA Collected, turned on  |
| Transaction codes | `transaction_codes`                             | One `import_links` row for each wastays code, to its estatell transaction code, payment method, charge item or tax. The replay finds every folio line's target with one lookup   |

- A wastays code with folio lines that cannot be mapped stops the import with its code and line count. A code with no lines is listed as skipped.
- Room revenue, no-show, cancellation, late check-out, early departure, security deposit, refund, adjustment and rebate map to the estatell system code with the same meaning.

### Guest import

`wastays:import-guests` writes one estatell guest per wastays guest in `guests.csv` (440 for GEH) through `CreateGuest`. It does not merge duplicates: the replay attaches bookings by wastays guest id. Staff can merge later.

| wastays                                                                              | estatell                                                                                                                                            |
| ------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| `name`, `email`                                                                      | `full_name`, `email`. An invalid email is dropped and counted                                                                                       |
| `phone`                                                                              | E.164, read as a Malaysian number when it has no country code. A number that cannot be read is left out and kept as a guest note "wastays phone: …" |
| `country`, `address_country`                                                         | `nationality_code`, `country_code`, matched by country name. A name that does not match is left out and counted                                     |
| `home_address`, `city`, `postal_code`, `state_code`                                  | `address_line1`, `city`, `postal_code`, `state`                                                                                                     |
| `date_of_birth`, `gender`                                                            | Same fields                                                                                                                                         |
| `vip`, `blacklisted`                                                                 | `SetVip`. `BlacklistGuest` with the reason "Blacklisted in wastays"                                                                                 |
| `document_type`, `government_id`, `passport_number`, `passport_issuing_country_code` | `AddIdentityDocument`. `malaysian_nric` → MyKad, normalized. `passport` → Passport with the issuing country. No type: 12 digits → MyKad, else Other |
| `discarded_at`                                                                       | Imported anyway, because bookings can still point to the guest                                                                                      |
| `tin`                                                                                | Not imported. The hotel does not use e-invoice                                                                                                      |

`booking_guests` is not read here. The replay uses it to put guests on reservations.

The build items are in the [checklist](../checklist.md#wastays-replay).

## Open at cutover

The replay checks guests in and out on their real days, so the guests in-house on the last replayed day are simply checked in. The 22 Oct dump had 9, all paid in advance (RM 1,300.20 credit). No opening balance is needed.

| Item              | Finding                                                         | In the replay                                                                                                                                                            |
| ----------------- | --------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Empty open folios | 7 at zero: 6 on voided bookings, 1 on a cancelled booking       | Replayed as voided or cancelled                                                                                                                                          |
| Security deposits | 2 × RM 50 held, bookings 5 and 9 (August), both bookings voided | Replayed as held. Ask the hotel                                                                                                                                          |
| Future bookings   | None after 22 Oct                                               | New ones replay as normal bookings                                                                                                                                       |
| OTA bookings      | Booking.com, Agoda, Traveloka                                   | Replayed from wastays up to cutover, with the OTA reference. Do not run Import future bookings: it would create them twice. Channex sends only new changes after connect |
| Companies and AR  | None                                                            | Nothing to do                                                                                                                                                            |

## Questions for the hotel

1. The two RM 50 deposits from 14 and 21 Aug: were they returned?
2. Is DBKK 1.30 still charged? Is TTX the tourism tax?
3. Which SST and tourism tax periods are already filed from wastays?
4. Which day is cutover? The window setting assumes cutover before 11 Nov. Suggest 3 Nov, which leaves a week for problems.

## Cutover runbook

1. The evening before: the last wastays night audit. Staff stop using wastays.
2. wastays terminal: `bin/rails "estatell:export[3]"`. In the R2 dashboard, download the zip from `wastays-bucket` and drag it into `estatell-production` at `imports/grand-eastern-hotel/`.
3. estatell terminal: `wastays:fetch`. Create the property, set `migration_window_days` to 90, `wastays:import-setup`, then `ApproveGoLive` with opening date 13 Aug 2026.
4. `wastays:import-guests`, then `wastays:replay` up to the last wastays date. Every day must match.
5. estatell opens cutover day. Staff start in estatell in the morning.
6. Connect Channex (no Import future bookings), run `wastays:purge`, delete the other zip copies, and tell the accountant which system covers which tax period.

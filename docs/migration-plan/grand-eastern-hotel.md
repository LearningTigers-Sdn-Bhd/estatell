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
| Corporate Rate | Derived from Standard, +0% | Follows Standard                                                                         | Everyone       |

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

`ApproveGoLive` opens the first business date on 13 Aug 2026. The importer then replays every wastays business date in order. `wastays:replay` loops over the dates; `ReplayWastaysDay` replays one.

**Which day a row belongs to.** Each wastays business date has a window in `hotel_business_dates`: `opened_at <= created_at < closed_at`. A row belongs to the day whose window holds its `created_at`. `posting_date` is not used: room, tax, correction and early-departure lines carry the night date, not the day they were posted.

**One timeline per day.** The day's events are sorted by time and replayed in that order, because wastays took payments before the check-out on the same day and estatell checks out only at a zero balance. Ties go in this order: create, check-in, charge, payment, reversal, stay change, check-out.

| #   | Event                                   | wastays rows                                                                                                                                                                                                         | estatell                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| --- | --------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 1   | Booking created                         | `bookings`, `booking_rooms`, `booking_guests`, audit log `create`                                                                                                                                                    | `CreateBooking`. A booking created after its arrival (87) is created with a past arrival and checked in as of that arrival, the two steps of `CreatePastStay`, which cannot be used itself because it refuses a stay that has already ended (79 of the 87) and prices no single night. The check-in posts the missed nights. One reservation per wastays booking; a group booking sets `group_name`. Final room from `booking_rooms.room_id`. Source and OTA reference copied. Rate plan by name |
| 2   | Night prices                            | The first room line wastays posted for each night, else `booking_rooms.nightly_rate_snapshot` (net). The snapshot holds only the latest price: a price typed after a night was charged changed only the later nights | `override_amount` through the total, then `ChangeNightPrice` for each group of equal prices when nights differ                                                                                                                                                                                                                                                                                                                                                                                   |
| 3   | Check-in                                | audit log `check_in`, `undo_check_in`, `edit_check_in`                                                                                                                                                               | `CheckInUnit` without a registration card; `UndoCheckIn`                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| 4   | Charges, payments, refunds, adjustments | `folio_transactions` not posted by the audit, catch-up or tax, and `deposits`                                                                                                                                        | See [Mapping](#mapping)                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| 5   | Stay changes, cancels, voids            | audit log `update` (dates, room type), `cancel`, `void`, `undo_check_out`                                                                                                                                            | `ChangeUnitDates`, `ExtendStay`, `MoveUnit`, `CancelUnit` (after `UndoCheckIn` when checked in), `UndoCheckOut`. A booking is created with its first dates from the audit log, then changed                                                                                                                                                                                                                                                                                                      |
| 6   | Early check-out                         | early-departure line                                                                                                                                                                                                 | `ChangeUnitDates` without a fee, `PostUnitPolicyFee` with the wastays amount for each line (wastays posts one per unused night), then `CheckOutAndRefund` after the last, in one transaction                                                                                                                                                                                                                                                                                                     |
| 7   | Check-out                               | audit log `check_out`                                                                                                                                                                                                | `CheckOutUnit`, which closes the folio with the wastays invoice number. A same-day leaver passes `sameDayLeaver: true` (see below)                                                                                                                                                                                                                                                                                                                                                               |
| 8   | Night audit                             | `night_audits`                                                                                                                                                                                                       | `RunNightAudit`. The next date opens                                                                                                                                                                                                                                                                                                                                                                                                                                                             |

- Every write goes through estatell Actions, never direct inserts. Every wastays row written gets an `import_links` row, so a stopped run resumes and posts nothing twice.
- Room and SST lines are not copied. The audit and `PostMissedNights` post them from `override_amount`; the compare checks them.
- The replay posts normal lines. It needs no bridge: no migrated stays, no opening balances, no Old system payments, no Old stays and no copied daily figures. The audit builds the daily figures.
- After the last wastays audit, estatell opens the next date, which is the cutover day. Staff start work in estatell on that day.

**Frozen clock.** The replay sets the clock to 12:00 property time on date D for the day's events, and to 00:05 on D+1 for `RunNightAudit`, then restores it. Dry runs are repeatable, `business_date_not_ended` passes, and the "audit pending" check-out logic never applies. Replayed lines carry that clock time.

**No messages.** During the run the queue is `null` and mail is `array`, so the audit sends no notifications.

**Refusals.** The replay refuses while a Channex channel is connected, when the estatell business date is not D, and when `payment_transactions` or `refund_requests` are not empty (GEH has none).

**Accepted differences.** The compare does not stop on a day with an accepted case, but it prints that day's differing figures at the end of the run, so each one can be checked against its case:

| Case                                                                   | Count       | In estatell                                                                                                                                                                                                                                                                                                 |
| ---------------------------------------------------------------------- | ----------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Check-in before the arrival date                                       | 10          | Checked in on the arrival day                                                                                                                                                                                                                                                                               |
| Arrived and left on the same day, charged one night as early departure | 11          | A one-night stay checked out on its arrival day with `CheckOutUnit(sameDayLeaver: true)`: the night posts at the check-out with the audit's key, priced at the wastays early departure fee when the stay was moved to the night before. Same money and the wastays invoice number and day, but room revenue |
| Room moves and room type changes during a stay                         | 47 bookings | Only the final room is used                                                                                                                                                                                                                                                                                 |
| Registration cards                                                     | 682         | Not imported. The numbers stay in wastays                                                                                                                                                                                                                                                                   |

### Document numbers

estatell builds the same prefix (`GEH`) and the same reference format as wastays. Guests already hold wastays invoices and receipts, so each replayed document keeps its wastays number: invoice `GEH-26700123` in estatell is the same stay as in wastays.

| Document          | wastays               | In the replay                                                                      |
| ----------------- | --------------------- | ---------------------------------------------------------------------------------- |
| Reservation       | `GEH-261xxxxx`        | Same number                                                                        |
| Registration card | `GEH-262xxxxx`        | Not imported. estatell cards need a signature. The numbers stay in wastays         |
| Receipt, invoice  | `GEH-265…`, `-267…`   | Same number                                                                        |
| Folio             | Plain number, 1, 2, 3 | estatell numbers it (`GEH-263xxxxx`). The wastays number is kept in `import_links` |

The numbers are issued deep inside `CreateReservation`, `CloseFolio` and `RecordPayment`. The replay pushes the wastays number onto `PlannedDocumentNumbers`, a queue per document type, just before it calls the Action. `NextDocumentNumber` takes the queued number when one is waiting; the queue must be empty afterwards, or the replay stops. The sequence still refuses a number that is not above `last_number`. In the desk flow the queue is always empty, so nothing changes. Group bookings had their own wastays reservation numbers (22); those numbers stay unused.

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
2. Replay all days. Each day is compared with its wastays night audit (`night_audit_financial_summaries`): room revenue, tax, payments, refunds, adjustments and no-show charges, from the folio lines estatell posted on the business date. wastays catch-up nights (no night audit, an earlier night date) count on the day whose window holds them, because no wastays summary counts them and estatell posts them that day. Early departure fees are left out of room revenue: the wastays summary counts only their tax. The document numbers are checked as the days replay: a number the sequence refuses stops the run. A day that shows an accepted difference is listed, not stopped on.
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
                                                                               wastays:import-guests
                                                                               ApproveGoLive (13 Aug)
                                                                               wastays:replay
                                                                               wastays:purge
```

- The export uploads through the wastays Active Storage R2 service, and `wastays:fetch` reads the existing `r2` disk. No new bucket, token or env value. Both buckets are in the same Cloudflare account: download the zip from `wastays-bucket` and drag it into `estatell-production` at `imports/grand-eastern-hotel/`.
- The zip holds decrypted guest data. After cutover, delete it from both buckets and from the laptop that copied it. `wastays:purge` removes only the estatell copies.
- `manifest.json` holds the hotel id, the last audit date, the row count of each file and the checksum. `wastays:fetch` refuses a bundle that does not match it.
- `import_links` (property, source table, source id, target type, target id, unique) records every wastays row already written, so a stopped run resumes and posts nothing twice.
- `wastays:replay {bundle} {property} --actor= --until= --dry-run` refuses while a Channex channel is connected, and refuses a day when the estatell business date is not the wastays date. The first day that does not match stops the run with a table of the differences and a non-zero exit.
- Replayed lines carry the frozen replay clock time (see [Day loop](#day-loop)). The activity log marks them "replay".
- The export also holds `booking_audit_logs` (create, check-in, check-out, update, cancel, void and undo rows only, without guest keys). It is the only record of when those events happened.
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

| wastays `folio_transactions`                  | Count | estatell                                                                                                                                                                                                                                               |
| --------------------------------------------- | ----- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| ROOM (night audit, catch-up, blocker, repair) | 902   | Not read. Posted by the audit from `override_amount`. A repaired price is `ChangeNightPrice`                                                                                                                                                           |
| TAX_SST                                       | 920   | Not read. estatell calculates 8% on top                                                                                                                                                                                                                |
| TAX_TTX and the manual TTX charge             | 5     | Waits for the hotel's answer. Tourism tax stays off during the replay                                                                                                                                                                                  |
| EARLY_DEP                                     | 18    | The early check-out in the [Day loop](#day-loop)                                                                                                                                                                                                       |
| Corrections                                   | 24    | `ChangeNightPrice` for a repaired night, `ReverseLine` for others, `PostAdjustment` for staff ones                                                                                                                                                     |
| ADJUSTMENT                                    | 5     | `PostAdjustment`: a signed amount on the folio, no tax                                                                                                                                                                                                 |
| OTA                                           | 433   | `RecordPayment` with OTA collected                                                                                                                                                                                                                     |
| CASH, CARD, BANK                              | 275   | `RecordPayment` with the linked method. Before check-in the purpose is Advance, after it Settlement. When the method needs a reference and wastays saved none (20 card, 14 bank), the reference is the wastays receipt, such as `wastays GEH-26500012` |
| REFUND                                        | 3     | `VoidPayment` for a same-day reversal of a payment, `RefundPayment` otherwise                                                                                                                                                                          |
| CLEANING, BED                                 | 4     | `PostCharge` with the linked charge item and the wastays amount                                                                                                                                                                                        |
| `deposits` (2 × RM 50 on voided bookings)     | 2     | `RecordPayment` with purpose Security deposit. Replayed as held                                                                                                                                                                                        |
| Tourism tax voucher references                | -     | Not carried over. estatell has no voucher number                                                                                                                                                                                                       |

- The wastays transaction code of a line finds its estatell target through the `import_links` that `wastays:import-setup` wrote.
- `PostCharge` and `BillToGuest` accept an admin actor (`is_admin`), not only property staff, so the replay can run as the admin.
- OTA collected is not offered at the desk. `NewPaymentData` gets an `otaCollected` flag that only the replay sets.

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

| Part              | From wastays                                    | In estatell                                                                                                                                                                                                                                                                       |
| ----------------- | ----------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Room types        | `room_types`                                    | `CreateRoomType`, sold by room. Name in Title Case. Max adults and max children as in wastays. Base occupancy 2, or max adults when lower                                                                                                                                         |
| Rooms             | `rooms`                                         | `CreateRoom`, same number and type. No room groups                                                                                                                                                                                                                                |
| Rate plans        | `rate_plans`                                    | The seeded Basic Rate stays named Basic Rate: the go-live check looks for that name. Walk-in Rate and Corporate Rate are derived from it at +0% (`CreateDerivedRatePlan`). Corporate is open to everyone, because wastays booked it without a company (10 bookings, no companies) |
| Rates             | `room_types.base_price`                         | `SaveRates` on Basic Rate from the first audit date to 31 Dec 2027, at base ÷ 1.08 rounded to 2 decimals                                                                                                                                                                          |
| SST               | `hotels.sst_enabled`, `sst_registration_number` | The seeded SST tax is turned on: 8%, exclusive, on room revenue, with the registration number                                                                                                                                                                                     |
| Tourism tax       | `TAX_TTX` and the manual `TTX` charge           | Stays off during the import and the replay: wastays charged only 4 of 66 foreign bookings. It is turned on at cutover, once the hotel answers                                                                                                                                     |
| DBKK              | `hotel_taxes` DBKK, off, no lines               | Skipped                                                                                                                                                                                                                                                                           |
| Charge items      | `hotel_extra_charges`                           | Food & Beverage → the starter item. Damage Charge → the built-in Damage item. Cleaning Fee, Miscellaneous Revenue, Parking and Extra Bed → new items, no price. Parking inactive                                                                                                  |
| Payments          | `hotel_payment_methods`                         | Cash, Cash - Prepayment, Cash Prepayment → Cash. Card, Card Prepayment, Gateway Manual Recovery → Card. Bank Transfer → Bank transfer. OTA Collected → OTA Collected, turned on                                                                                                   |
| Transaction codes | `transaction_codes`                             | One `import_links` row for each wastays code, to its estatell transaction code, payment method, charge item or tax. The replay finds every folio line's target with one lookup                                                                                                    |

- A wastays code with folio lines that cannot be mapped stops the import with its code and line count. A code with no lines is listed as skipped.
- Room revenue, no-show, cancellation, late check-out, early departure, security deposit, refund, adjustment and rebate map to the estatell system code with the same meaning.

### Guest import

`wastays:import-guests` writes one estatell guest per wastays guest in `guests.csv` (440 for GEH) through `CreateGuest`. It does not merge duplicates: the replay attaches bookings by wastays guest id. Staff can merge later.

| wastays                                                                              | estatell                                                                                                                                               |
| ------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `name`, `email`                                                                      | `full_name`, `email`. An invalid email is dropped and counted                                                                                          |
| `phone`                                                                              | E.164, read as a Malaysian number when it has no country code. A number that cannot be read is left out and kept as a guest note "wastays phone: …"    |
| `country`, `address_country`                                                         | `nationality_code`, `country_code`, matched by country name. A name that does not match is left out and counted                                        |
| `home_address`, `city`, `postal_code`, `state_code`                                  | `address_line1`, `city`, `postal_code`, `state`                                                                                                        |
| `date_of_birth`, `gender`                                                            | Same fields                                                                                                                                            |
| `vip`, `blacklisted`                                                                 | `SetVip`. `BlacklistGuest` with the reason "Blacklisted in wastays"                                                                                    |
| `document_type`, `government_id`, `passport_number`, `passport_issuing_country_code` | `AddIdentityDocument`. `malaysian_nric` or no type: 12 digits after normalizing → MyKad, else Other. `passport` → Passport, issuing country as alpha-2 |
| `discarded_at`                                                                       | Imported anyway, because bookings can still point to the guest                                                                                         |
| `tin`                                                                                | Not imported. The hotel does not use e-invoice                                                                                                         |

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
3. estatell terminal: `wastays:fetch`. Create the property, set `migration_window_days` to 90, run `wastays:import-setup` and `wastays:import-guests`, then `ApproveGoLive` with opening date 13 Aug 2026. Both imports refuse a live property, so they run first.
4. Rename Basic Rate to Standard Rate on the Rates page.
5. `wastays:replay` up to the last wastays date. Every day must match.
6. Turn on Tourism tax, if the hotel confirms TTX is that tax.
7. estatell opens cutover day. Staff start in estatell in the morning.
8. Connect Channex (no Import future bookings), run `wastays:purge`, delete the other zip copies, and tell the accountant which system covers which tax period.

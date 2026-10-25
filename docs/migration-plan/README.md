# Migration plan: wastays to estatell

## Purpose

One file per hotel that moves from wastays to estatell. Each file says what moves, what stays in wastays, and what the hotel must answer first. The general rules are in [Migration from another PMS](../17-onboarding/07-migration.md).

## Source

All figures come from a read-only clone of wastays production (`wastays_prod_clone`, dump of 22 Oct 2026, business date 22 Oct 2026). The clone and the dump hold real guest data. They stay outside the repo and are deleted after the migration.

## Hotels

| Hotel                                                                 | wastays id | Path           | Status                                  |
| --------------------------------------------------------------------- | ---------- | -------------- | --------------------------------------- |
| [Grand Eastern Hotel](grand-eastern-hotel.md)                         | 3          | Full replay    | Done. Replayed on production            |
| [Sand Bay Resort Semporna](sand-bay-resort-semporna.md)               | 5          | Full migration | Done. Replayed, companies billed        |
| [Y Hotel](y-hotel.md)                                                 | 4          | Copy setup     | Done. Check its rates (SST off)         |
| [Hotel Asia City](hotel-asia-city.md)                                 | 6          | Copy setup     | Done. Live 26 Oct. Service Tax to ask   |
| [Hotel Puri 36](hotel-puri-36.md)                                     | 8          | Copy setup     | Done. Live 26 Oct. Tax basis to confirm |
| [Summer Holiday](summer-holiday.md)                                   | 10         | Copy setup     | Done. Live 26 Oct. Bunk Bed per room?   |
| [LKH Motel](lkh-motel.md)                                             | 9          | Start fresh    | Nothing to copy                         |
| [Amanah Shar Hotel and Apartment](amanah-shar-hotel-and-apartment.md) | 11         | Start fresh    | Nothing to copy                         |

Not migrated: Hotel Boreno (1, test data), Gail Decker (2, test data), FPHP UiTM Sabah (7, suspended, not a customer).

## Paths

| Path           | Moves                                                                                                                                  |
| -------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| Full replay    | Setup and guests, then every wastays day replayed through Actions and the night audit from the first wastays business date. No bridges |
| Full migration | Setup, guests, past stays as Old stays, in-house stays, future bookings, deposits, company balances, past daily figures                |
| Copy setup     | Room types, active rooms, rate plans with 12 months of daily rates, taxes, extra charges, payment methods                              |
| Start fresh    | Nothing. The hotel uses the estatell onboarding wizard                                                                                 |

## Method

- An Artisan importer reads the clone through a read-only database connection and writes through estatell Actions. It never inserts rows directly, so ledger rules, idempotency keys and the migration window still apply.
- Guest email, phone, government ID and passport number are Active Record encrypted in wastays. A small Rails export task in wastays decrypts them. Everything else is read with SQL.
- OTA future bookings come in through Channex `ImportFutureBookings`, never from wastays, so they are not imported twice.
- Past daily figures are copied from wastays night audits once and marked as from the old system. They are never recalculated. A full replay builds them with the estatell audit instead.
- Every run starts with a dry run that lists problems. A real run is repeatable: the wastays id is the idempotency key (to decide where it is stored).

## Rate plan mapping

wastays has one standing price per room type and plan. estatell keeps a price per date. The import creates 12 months of daily rates from go-live.

| wastays                                        | estatell                                                         |
| ---------------------------------------------- | ---------------------------------------------------------------- |
| The same plan name repeated once per room type | One plan linked to every room type                               |
| `sell_mode` per_room                           | PerRoom                                                          |
| `sell_mode` per_person with occupancy prices   | PerGuest with `rate_occupancy_prices`                            |
| Pair `fixed` with no value                     | Daily rates from `room_types.base_price`                         |
| Pair `fixed` with a value                      | Daily rates from that value                                      |
| Pair `multiplier` or `offset`                  | Derived plan, Percent or Amount, from Standard Rate              |
| `day_use_hours` set                            | Day use plan with one hour price in `rate_hour_prices`           |
| `rate_plan_age_bands`                          | Property age groups and `rate_plan_child_prices`                 |
| `rate_plan_stay_discounts`                     | `rate_plan_stay_discounts`                                       |
| `ta_access` hidden                             | Company access Everyone                                          |
| `ta_access` all                                | All companies                                                    |
| `ta_access` only                               | Only these companies (estatell allows it on a derived plan only) |
| `ta_access` except                             | No match in estatell. Decide per plan                            |
| Archived plans and archived rooms              | Not imported                                                     |

**Tax basis.** Grand Eastern's prices include SST (162.00 posts as 150.00 + 12.00 SST), so `wastays:import-setup` divides them by 1.08. Hotel Asia City's and Hotel Puri 36's are already before tax (93.22 + 18% = 110.00; 88.89 + 8% = 96.00): run them with `--net-prices`. A hotel with SST off keeps its prices as they are, with or without the flag. Check one real booking per hotel before the first run.

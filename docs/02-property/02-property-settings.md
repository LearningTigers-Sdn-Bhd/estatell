# Property settings

## Purpose

Operating settings for one property, kept out of the `properties` table.

## Data model

```
property_settings    property_id (unique), currency, time_zone, default_locale,
                     check_in_time, check_out_time, arrival_grace_minutes,
                     auto_assign_rooms,
                     audit_time, auto_audit_enabled,
                     missed_arrival_policy, overdue_departure_policy, no_show_charge,
                     registration_card_scope, card_required_for_check_in,
                     id_document_retention_days, security_deposit_amount,
                     allow_support_access (bool, default true),
                     weekend_nights (WeekendNights, default FriSat),
                     timestamps
```

- A 1:1 table with typed columns. New settings are new columns.
- The first Basics save calls `SeedPropertyDefaults`, which creates the row from the Malaysia preset. The migration defaults stay aligned as a database fallback. Repeating Basics does not replace edited settings.
- Current property staff can edit the page. Finer permissions arrive with the roles module.

## Enums

- `Currency`: Myr. More currencies arrive with other countries.
- `Locale`: English, Malay.
- `MissedArrivalPolicy`: MarkNoShow, KeepAsLateArrival.
- `OverdueDeparturePolicy`: ExtendOneNight, Block.
- `NoShowCharge`: FirstNight, FullStay, None.
- `RegistrationCardScope`: PrimaryOnly, EveryAdult.
- `WeekendNights`: FriSat ("Friday + Saturday"), SatSun ("Saturday + Sunday").

## Weekend nights

`weekend_nights` is edited on the Operations tab. It is only the preselected choice in the [pricing form](../08-rates/02-daily-rates-and-restrictions.md#pricing-form). Changing it does not change existing rates. It is an enum, not a weekday array.

## Support access

`allow_support_access` lets platform support view the property as its owner. The owner can switch it off. See [Impersonation](../01-platform/04-impersonation.md).

## Rules

- A property has one currency. Money columns elsewhere do not store currency.
- Currency and time zone are fixed once the property has gone live (`went_live_at` is set). The page shows them disabled, and the request drops them (`SettingsLock`).
- All business dates and schedules use `time_zone`.
- The Settings page has five tabs, each with its own URL: General, Operations, Registration card, Age groups and Support access. Each tab saves separately. The Age groups tab saves through its own route (see [Age groups](04-age-groups.md)). Before Basics is complete, it links to `/p/{code}/onboarding/basics`.
- Initial values are MYR, `Asia/Kuala_Lumpur`, English, 15:00 check-in, 11:00 check-out, 02:00 audit, no automatic audit or room assignment, no no-show charge, blocked overdue departures, primary-only registration cards, no card requirement, zero deposit, Friday + Saturday weekend nights and support access on. ID retention is unset until document capture exists.

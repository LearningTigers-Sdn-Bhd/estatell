# Property settings

## Purpose

Operating settings for one property, kept out of the `properties` table.

## Data model

```
property_settings    property_id (unique), currency, time_zone, default_locale,
                     check_in_time, check_out_time, arrival_grace_minutes,
                     auto_assign_rooms,
                     audit_time, auto_audit_enabled,
                     missed_arrival_policy, overdue_departure_policy,
                     registration_card_scope, card_required_for_check_in,
                     id_document_retention_days, security_deposit_amount,
                     allow_support_access (bool, default true),
                     allow_support_money_corrections (bool, default true),
                     weekend_nights (WeekendNights, default FriSat),
                     timestamps
```

- A 1:1 table with typed columns. New settings are new columns.
- The first Basics save calls `SeedPropertyDefaults`, which creates the row from the Malaysia preset. The migration defaults stay aligned as a database fallback. Repeating Basics does not replace edited settings.
- Current property staff can edit the page. Finer permissions arrive with the roles module.

## Enums

- Currency: a 3-letter code cast to `Brick\Money\Currency` (`AsCurrency`). See [Currencies](03-seed-property-defaults.md#currencies).
- `Locale`: English, Malay.
- `MissedArrivalPolicy`: MarkNoShow, KeepAsLateArrival.
- `OverdueDeparturePolicy`: ExtendOneNight, Block.
- `NoShowCharge` (FirstNight, FullStay, None) was replaced by the no-show [room revenue policy](../06-catalogues/06-room-revenue.md#policies). A migration copied each property's value into its no-show policy (First night and Full stay became 100% of the booked rate) and dropped the column and the enum.
- `RegistrationCardScope`: PrimaryOnly, EveryAdult.
- `WeekendNights`: FriSat ("Friday + Saturday"), SatSun ("Saturday + Sunday").

## Weekend nights

`weekend_nights` is edited on the Operations tab. It is only the preselected choice in the [pricing form](../08-rates/02-daily-rates-and-restrictions.md#pricing-form). Changing it does not change existing rates. It is an enum, not a weekday array.

## Support access

`allow_support_access` lets platform support and the property's partner view the property as its owner. The owner can switch it off. `allow_support_money_corrections` lets support also refund, void and issue credit notes during a session. Both sit on the Support tab. See [Impersonation](../01-platform/04-impersonation.md#money-corrections).

## Rules

- A property has one currency. Money columns elsewhere do not store currency.
- Country, currency and time zone are changed on Basics until go-live ([Changing country](03-seed-property-defaults.md#changing-country)). The settings page always shows them disabled. After go-live the request drops them (`SettingsLock`).
- All business dates and schedules use `time_zone`.
- The Details page has seven tabs, each with its own URL: Profile, Business licence, General, Operations, Age groups, Meal periods and Support access. Each tab saves separately. Profile is first, and the sidebar item opens it. It edits the property's identity and logo, not `property_settings` (see [Properties](01-properties.md#profile)). Business licence is second. It holds the registered name, registration number, TIN and licences (see [Properties](01-properties.md#business-licence)). The Age groups tab saves through its own route (see [Age groups](04-age-groups.md)). The registration card settings (scope, card required, ID retention, deposit) are on their own Registration card page, General tab. See [Card fields](../16-registration/01-card-fields.md#ui). PDF settings (font, size, letterhead, footer, and what the customer documents show) are on their own Printing page, in their own `print_settings` table. See [Printing settings](05-printing-settings.md). Before Basics is complete, it links to `/p/{code}/onboarding/basics`.
- Initial values are MYR, `Asia/Kuala_Lumpur`, English, 15:00 check-in, 11:00 check-out, 02:00 audit, no automatic audit or room assignment, no room revenue policy charges, blocked overdue departures, primary-only registration cards, no card requirement, zero deposit, Friday + Saturday weekend nights and support access on. ID retention is unset, so scans are kept until a number of days (at least 1) is set.

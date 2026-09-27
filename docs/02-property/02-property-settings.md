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
                     timestamps
```

- A 1:1 table with typed columns. New settings are new columns.

## Enums

- `MissedArrivalPolicy`: MarkNoShow, KeepAsLateArrival.
- `OverdueDeparturePolicy`: ExtendOneNight, Block.
- `NoShowCharge`: FirstNight, FullStay, None.
- `RegistrationCardScope`: PrimaryOnly, EveryAdult.

## Support access

`allow_support_access` lets platform support view the property as its owner. The owner can switch it off. See [Impersonation](../01-platform/04-impersonation.md).

## Rules

- A property has one currency. Money columns elsewhere do not store currency.
- All business dates and schedules use `time_zone`.

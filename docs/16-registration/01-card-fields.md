# Registration card fields

## Purpose

Each property chooses what its registration card shows and asks. Some hotels use the card as a booking summary, so the card can also show stay details and prices.

## Data model

```
registration_card_fields    id, property_id, field (RegistrationCardField),
                            visibility (FieldVisibility), position
                            unique (property_id, field)
```

- One row per field per property, always all 19. The model is `RegistrationCardFieldSetting`, because the enum already uses the name `RegistrationCardField`.
- `position` is the enum order. There is no reorder screen.

## Enums

- `RegistrationCardField` (19 cases, in card order). `group()`, `allowedVisibilities()`, `defaultVisibility()` and `label()` live on the enum.
- `RegistrationCardFieldGroup`: Guest, Stay, Prices.
- `FieldVisibility`: Hidden, Optional, Required.

| Group         | Fields                                                                                                              | Allowed values             |
| ------------- | ------------------------------------------------------------------------------------------------------------------- | -------------------------- |
| Guest details | FullName, Nationality, IdentityDocument, DateOfBirth, Gender, Address, Phone, Email, VehiclePlate, MarketingConsent | Hidden, Optional, Required |
| Stay details  | BookingNumber, CheckIn, CheckOut, Nights, GuestCount, RoomType, RoomNumber                                          | Hidden, Required           |
| Prices        | RatePerNight, TotalCharges                                                                                          | Hidden, Required           |

Stay details and prices are display-only. The guest does not fill them in. The UI shows one **Visibility** switch, stored as Hidden or Required.

## Defaults

One preset for every property type, seeded by `SeedRegistrationCardFields`.

| Field                                                                      | Default           |
| -------------------------------------------------------------------------- | ----------------- |
| FullName                                                                   | Required (locked) |
| Nationality, IdentityDocument, Phone                                       | Required          |
| Email, Address, DateOfBirth, VehiclePlate, MarketingConsent                | Optional          |
| Gender                                                                     | Hidden            |
| BookingNumber, CheckIn, CheckOut, Nights, GuestCount, RoomType, RoomNumber | Required (shown)  |
| RatePerNight, TotalCharges                                                 | Hidden            |

## Rules

- FullName is always Required. It cannot be changed.
- MarketingConsent is never Required. Consent is a free choice. It is a separate opt-in checkbox. When ticked it sets `guests.marketing_consent_at` (captured by the [signing flow](04-signing-flow.md)).
- Nationality drives the `foreign_guests_only` tax rule. It cannot be Hidden while an enabled foreign-only tax exists. `SaveRegistrationCardFields` refuses the change and the page shows the reason. Enabling a foreign-only tax later does not change the card.
- Seeding creates missing rows and never overwrites an edited row. A migration back-fills existing properties.
- `VehiclePlate` has no guest column. The signing flow stores it on the card.

## Booking summary

- Prices print the quote of the booking, with room charges and taxes only, under "Estimated charges at booking". Extras and payments stay on the folio.
- The price is copied into the card snapshot when the card is signed, so a later rate change does not change a signed card.
- The card hides prices when the reservation has a company, so a negotiated rate is not shown to the guest.
- With scope "Every adult", each adult sees the prices. A hotel that turns prices on accepts this.

## UI

The Registration card page is its own Settings sidebar item (`settings.manage`), below Details. It has tabs with their own URLs:

| Tab     | URL                                    | Content                                                                                                        |
| ------- | -------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| General | `/p/{code}/settings/registration-card` | Who completes a card, card required for check-in, ID retention, security deposit. These left the Details page. |
| Layout  | `…/registration-card/layout`           | The fields table and a live preview.                                                                           |
| Terms   | `…/registration-card/terms`            | Added with [Terms documents and presets](02-terms-documents-and-presets.md). Not shown until then.             |

General opens first. The Layout tab is full width, in a three-column grid. The table takes two columns and the preview takes one:

```
┌───────────────────────────────────────┬───────────────────────┐
│ Visibility │ Field         │ Required │ Preview (sticky)      │
│ ─ Guest details ──────────────────────│                       │
│ ─ Stay details ───────────────────────│                       │
│ ─ Prices ─────────────────────────────│                       │
└───────────────────────────────────────┴───────────────────────┘
```

- The table follows the charge items table. The switch is in the first column, headed **Visibility**, then the field name, then a **Required** switch.
- Required applies to guest-detail fields only, and only when the field is visible. Visible and not required is Optional.
- A locked row shows its switch disabled with the reason.
- One Save button for the table. The preview is drawn in the browser from the unsaved state, with sample data. The server sends the rows and the property name.

## Actions

`SeedRegistrationCardFields`, `SaveRegistrationCardFields` (saves all 19 rows in one transaction, returns false when Nationality would be hidden while a foreign-only tax is enabled, logs `registration_card_fields.updated` when something changed).

## Lessons from wastays

The field list was the `hotels.guest_registration_card_fields` jsonb column, with display fields only (phone, email, address, nationality, guest count, room type, room number, booking number, check-in and check-out). It had no required level and no prices.

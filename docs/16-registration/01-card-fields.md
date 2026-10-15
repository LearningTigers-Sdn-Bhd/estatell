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
- `position` is the card order. Seeding uses the enum order, and staff can move a field up or down inside its group (see [UI](#ui)). The groups keep their order: Guest details, Stay details, Prices.

## Enums

- `RegistrationCardField` (19 cases, in card order). `group()`, `allowedVisibilities()`, `defaultVisibility()` and `label()` live on the enum.
- `RegistrationCardFieldGroup`: Guest, Stay, Prices.
- `FieldVisibility`: Hidden, Optional, Required.

| Group         | Fields                                                                                                                       | Allowed values             |
| ------------- | ---------------------------------------------------------------------------------------------------------------------------- | -------------------------- |
| Guest details | FullName, Nationality, IdentityDocument, DateOfBirth, Gender, Address, Phone, Email, VehiclePlate, Remarks, MarketingConsent | Hidden, Optional, Required |
| Stay details  | BookingNumber, CheckIn, CheckOut, Nights, GuestCount, Room                                                                   | Hidden, Required           |
| Prices        | RatePerNight, TotalCharges                                                                                                   | Hidden, Required           |

The Room field prints the room type and the room number together, for example "Deluxe Queen · 204". It prints the room type alone until a room is assigned.

Check-in and Check-out print the full date with the year and the time, for example "12 Jun 2026, 15:00".

Stay details and prices are display-only. The guest does not fill them in. The UI shows one **Visibility** switch, stored as Hidden or Required.

## Defaults

One preset for every property type, seeded by `SeedRegistrationCardFields`.

| Field                                                                | Default           |
| -------------------------------------------------------------------- | ----------------- |
| FullName                                                             | Required (locked) |
| Nationality, IdentityDocument, Phone                                 | Required          |
| Email, Address, DateOfBirth, VehiclePlate, Remarks, MarketingConsent | Optional          |
| Gender                                                               | Hidden            |
| BookingNumber, CheckIn, CheckOut, Nights, GuestCount, Room           | Required (shown)  |
| RatePerNight, TotalCharges                                           | Hidden            |

## Rules

- FullName is always Required. It cannot be changed.
- MarketingConsent is never Required. Consent is a free choice. It is a separate opt-in checkbox. When ticked it sets `guests.marketing_consent_at` (captured by the [signing flow](04-signing-flow.md)).
- Nationality drives the `foreign_guests_only` tax rule. It cannot be Hidden while an enabled foreign-only tax exists. `SaveRegistrationCardFields` refuses the change and the page shows the reason. Enabling a foreign-only tax later does not change the card.
- Seeding creates missing rows and never overwrites an edited row. A migration back-fills existing properties.
- `VehiclePlate` has no guest column. The signing flow stores it on the card.
- `Remarks` is free text, up to 500 characters, for requests or notes about the stay. Staff and the guest can both write it. Like `VehiclePlate`, it belongs to the stay, so it lives on the card (`registration_cards.remarks`), not on the guest. It is separate from `reservations.notes`, which stays internal. The signing flow freezes it in the snapshot and the PDF prints it. The Layout tab places it after VehiclePlate. The back-fill migration adds the row to existing properties at that position, so the later positions move by one.

## Booking summary

This is the price block printed on the registration card. It is not the [booking summary](../12-reservations/08-booking-summary.md) document, which prints the whole reservation with every charge and payment.

- Prices print the quote of the booking, with room charges and taxes only, under "Estimated charges at booking". Extras and payments stay on the folio.
- The price is copied into the card snapshot when the card is signed, so a later rate change does not change a signed card.
- The card hides prices when the reservation has a company, so a negotiated rate is not shown to the guest.
- With scope "Every adult", each adult sees the prices. A hotel that turns prices on accepts this.

## UI

The Registration card page is its own Settings sidebar item (`settings.manage`), below Details. It has tabs with their own URLs:

| Tab     | URL                                    | Content                                                                                                                                                 |
| ------- | -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| General | `/p/{code}/settings/registration-card` | Who completes a card, card required for check-in, ID retention, security deposit. These left the Details page.                                          |
| Layout  | `…/registration-card/layout`           | The fields table and a live preview.                                                                                                                    |
| Terms   | `…/registration-card/terms`            | The terms editor, with a language Select. See [Terms documents and presets](02-terms-documents-and-presets.md). Needs `registration-card.manage-terms`. |

General opens first. It uses one vertical form:

- **Who completes a card** uses radio cards with descriptions: Primary guest only (one card for the primary guest) or Every adult (each adult completes their own card).
- **Require card before check-in** sits directly below the scope choice. Staff with override permission can bypass the requirement.
- **ID document retention (days)** has the placeholder “e.g. 90”. Leaving it blank keeps ID photos; document numbers stay when photos are deleted.
- **Security deposit** uses radio cards: Do not collect or Collect a deposit. Collect shows the amount input with the placeholder “e.g. 100.00”. Do not collect saves zero through the existing amount field. Switching between the choices keeps an unsaved amount until the page is left.
- Radio cards place the radio indicator on the right. Cards sit side by side on larger screens and stack on mobile. **Save changes** saves the form. Layout and Terms are unchanged.

The Layout tab is full width, in a three-column grid. The table takes two columns and the preview takes one:

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
- An **Order** column has up and down arrows (no drag-and-drop package, like room types). A field moves only inside its group. The preview follows the new order at once, and the order saves automatically with the visibilities.
- There is no Save button. Every change saves by itself 300 ms after the last click, as one request with all visibilities and the order. The header shows "Saving…" and then "Changes save automatically." A success shows no toast. A refused save shows an error toast and the table snaps back to the server copy.
- The preview is drawn in the browser from the table state, with sample data. The server sends the rows and the property name.

## Actions

`SeedRegistrationCardFields`, `SaveRegistrationCardFields` (saves the visibility and order of all 19 rows in one transaction, returns false without writing when Nationality would be hidden while a foreign-only tax is enabled or the order moves a field out of its group, logs `registration_card_fields.updated` when something changed).

## Lessons from wastays

The field list was the `hotels.guest_registration_card_fields` jsonb column, with display fields only (phone, email, address, nationality, guest count, room type, room number, booking number, check-in and check-out). It had no required level and no prices.

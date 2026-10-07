# Properties

## Purpose

A property is the tenant. Every domain record belongs to exactly one property. wastays called this `hotels`.

## Data model

```
properties    id, code (bigint, unique), document_prefix (unique, nullable), name, type (PropertyType, nullable), status (PropertyStatus),
              address_line1, address_line2, city, state_code, postal_code, country_code (char 2, nullable),
              contact_email, contact_phone, registration_number, tin,
              onboarding_started_at, went_live_at, suspended_at, suspended_from_status, timestamps
```

- `type` and `country_code` are empty after self sign-up. The Basics wizard step fills them (see [Wizard steps](../17-onboarding/02-wizard-steps.md)).
- CHECK constraints keep `type` and `status` to enum values.

## Property code

- `code` is the public identifier: a plain number, issued in order from the Postgres sequence `property_code_seq` starting at **10101** (column default).
- Immutable after creation. It is not fillable, so application code cannot set or change it.
- Used in every staff URL: `app.example.com/p/10142/...` (see [Routing](../routing.md)).
- Easy to say on the phone. Admin ⌘K searches by code, name or city.
- Not a secret. Access is always checked by `EnsurePropertyAccess`; a code that does not belong to the user returns `404`.
- `slug` is added later, only when the public booking engine needs it.

## Document prefix

- `document_prefix` starts every document reference of the property (for example `GRH-26500042`). It is built from the name when the property is created and does not change when the name changes.
- Every prefix is kept in `property_document_prefix_histories`, so a retired prefix is never reused.
- Details: [Business dates](../20-night-audit/01-business-dates.md#document-references).

## Enums

- `PropertyType`: Hotel, Hostel, Resort, ServicedApartment, Homestay.
- `PropertyStatus`: Onboarding, PendingApproval, Live, Suspended.

| From            | Can move to                            |
| --------------- | -------------------------------------- |
| Onboarding      | PendingApproval, Live, Suspended       |
| PendingApproval | Onboarding (rejected), Live, Suspended |
| Live            | Suspended                              |
| Suspended       | Onboarding, PendingApproval, Live      |

The admin suspension action stores the current status in `suspended_from_status` and sets `suspended_at`. Reactivation restores that status and clears both fields. Existing suspended records are backfilled to Live when `went_live_at` exists, or Onboarding otherwise.

## Profile

The property's identity, edited on the Details page, **Profile** tab (`/p/{code}/settings/profile`, `settings.manage`). It is the first tab, and the Details sidebar item opens it.

```
┌ Profile ─────────────────────────────────────────────┐
│ Logo        [img] [Replace] [Remove]   PNG/JPEG ≤1 MB│
│ Name        [ Sandbay Resort                      ]  │
│ Address     [ Lot 12, Jalan Pantai… ] [ line 2 ]     │
│             [ Postcode ] [ City ] [ State ▾ ]        │
│ Phone       [ +60 88 123 456 ]  Email [ stay@… ]     │
│ Business registration no. [ 202301012345 ]           │
│ TIN         [ C1234567890 ]                          │
│                                          [ Save ]    │
└──────────────────────────────────────────────────────┘
```

- Fields: name, address, contact phone and email, registration number and TIN. The name and address use the Basics rules (`SavePropertyBasicsRequest::identityRules()`).
- Type and country are shown and locked. They seeded the currency, taxes and presets. Before Basics is complete, the tab links to Basics like the other tabs.
- `UpdatePropertyProfile` (`app/Actions/Property/`) saves the fields and logs `property.updated` with the changed fields. A save that changes nothing is not logged. It does not change `document_prefix`.
- Printed documents read these values through [Printing settings](05-printing-settings.md). Empty values show "Not set" there with a link back to this tab.

### Logo

- A single-file `logo` media collection on Property, on the platform media disk (private). PNG or JPEG, up to 1 MB. SVG is refused because dompdf draws it poorly.
- The logo uploads as soon as a file is picked. It is not part of Save.
- `UploadPropertyLogo` replaces the file. `RemovePropertyLogo` deletes it. Each logs `property.updated` with `logo`.
- `settings.logo` streams the file to staff with `settings.manage`, for the browser previews. PDFs embed the file as a data URI, read from its disk.
- The app shell and the guest registration screen can show it later. See [App shell](../design-system/09-app-shell.md).

| Route                                   | Name                                           |
| --------------------------------------- | ---------------------------------------------- |
| `GET`, `PUT /p/{code}/settings/profile` | `settings.profile`, `settings.profile.update`  |
| `POST`, `DELETE …/settings/logo`        | `settings.logo.store`, `settings.logo.destroy` |
| `GET …/settings/logo`                   | `settings.logo`                                |

## Rules

- Every domain table has `property_id`, and every query is scoped by it.
- The current property is resolved from the `{property:code}` route parameter by `EnsurePropertyAccess`, and must match `$user->property` (or an active impersonation session).
- `PropertyType` drives defaults (see [Seed property defaults](03-seed-property-defaults.md)).
- Operational routes require `Live` (see `EnsurePropertyIsLive` in [Wizard steps](../17-onboarding/02-wizard-steps.md)).
- Suspended staff are redirected from property routes to `/p/{code}/suspended`, a read-only notice. Personal account routes remain available. After reactivation, the notice route redirects to the dashboard.

## Lessons from wastays

- `hotels` had 74 columns mixing identity, tax, AI, training and feature flags. Keep `properties` to identity and address. Operating settings go to [Property settings](02-property-settings.md).
- Tourism tax existed both as columns on `hotels` and as rows in `hotel_taxes`. Taxes live only in `taxes`.
- wastays had four identifiers: `unique_id`, `slug`, `public_id` (UUID) and `hotel_prefix` with a prefix history table. `unique_id` was issued with an advisory lock and `MAX + 1`. estatell keeps one `code` from a database sequence.

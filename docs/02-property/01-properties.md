# Properties

## Purpose

A property is the tenant. Every domain record belongs to exactly one property. wastays called this `hotels`.

## Data model

```
properties    id, code (varchar 12, unique), partner_id (nullable), document_prefix (unique, nullable), name, type (PropertyType, nullable), status (PropertyStatus),
              address_line1, address_line2, city, state_code, postal_code, country_code (char 2, nullable),
              contact_email, contact_phone, legal_name (nullable), registration_number, tin,
              onboarding_started_at, went_live_at, suspended_at, suspended_from_status, timestamps
property_licences  id, property_id, issuer, number, expires_on (nullable), show_on_documents (default false),
              position, timestamps
              CHECK issuer and number are not blank
```

- `type` and `country_code` are empty after self sign-up. The Basics wizard step fills them (see [Wizard steps](../17-onboarding/02-wizard-steps.md)).
- `partner_id` is the partner who brought the property (restrict on delete). NULL means a direct customer. Only admins change it (see [Partners](../17-onboarding/05-partners.md)).
- CHECK constraints keep `type` and `status` to enum values.
- `legal_name` is the registered business name (for example "Seaview Hospitality Sdn Bhd") when it differs from the trading `name`. Empty text is stored as null.
- `property_licences` holds licences such as a local council business licence (for example Dewan Bandaraya Kota Kinabalu), MOTAC registration or a fire certificate. `issuer` and `number` are free text (at most 150 and 50 characters). `expires_on` is optional and can drive an expiry reminder later. Rows are ordered by `position` and can be deleted.
- The labels of `registration_number` and `tin` come from the [country profile](03-seed-property-defaults.md#country-profiles): "SSM registration no." and "TIN (LHDN)" for Malaysia, "Business registration no." and "Tax ID (TIN)" otherwise.

## Property code

- `code` is the public identifier: a 2-letter prefix and a number, for example `ES10101` or `AS10101`. CHECK `^[A-Z]{2}[0-9]{5,}$`.
- `ES` is for direct properties (self sign-up without a partner link, or created by an admin). A partner's properties use the partner's `code_prefix` (see [Partners](../17-onboarding/05-partners.md)).
- Each prefix has its own counter, starting at **10101**. `ES10101` and `AS10101` can both exist.
- Counters live in `property_code_counters` (`prefix` char 2 primary key, `last_number`). `IssuePropertyCode` takes the next number with one `INSERT … ON CONFLICT (prefix) DO UPDATE SET last_number = last_number + 1 RETURNING last_number` inside the creating transaction, so two sign-ups never get the same code.
- Immutable after creation. It is not fillable. Moving a property to another partner keeps its code, so the prefix shows where the property started, not who it belongs to now.
- Used in every staff URL: `app.example.com/p/ES10142/...` (see [Routing](../routing.md)). The route binding upper-cases the code, so `/p/es10142` also works.
- Easy to say on the phone. Admin ⌘K searches by code, name or city.
- Not a secret. Access is always checked by `EnsurePropertyAccess`; a code that does not belong to the user returns `404`.
- Not the [document prefix](#document-prefix), which is a separate column.
- `slug` is added later, only when the public booking engine needs it.
- History: codes were plain numbers from the sequence `property_code_seq` until the partners change. The migration prefixed every existing code with `ES` (10142 → `ES10142`), seeded the `ES` counter with the highest number, and dropped the sequence.

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
│                                          [ Save ]    │
└──────────────────────────────────────────────────────┘
```

- Fields: name, address and contact phone and email. The registered name, numbers and licences are on the [Business licence](#business-licence) tab. The name and address use the Basics rules (`SavePropertyBasicsRequest::identityRules()`).
- The logo sits above the form. Fields are grouped into Property, Address and Contact. On desktop, postcode, city and state share one row; phone and email share another. Fields stack on mobile. One **Save profile** button saves all three groups.
- Type and country are shown and locked. They seeded the currency, taxes and presets. Before Basics is complete, the tab links to Basics like the other tabs.
- `UpdatePropertyProfile` (`app/Actions/Property/`) saves the fields and logs `property.updated` with the changed fields. A save that changes nothing is not logged. It does not change `document_prefix`.
- Printed documents read these values through [Printing settings](05-printing-settings.md). Empty values show "Not set" there with a link back to this tab.

All seven Details tabs disable Save until a field changes. They reuse the shared unsaved-changes guard and dialog when leaving or switching tabs: Cancel, Discard or Save. Save leaves only after a successful response; validation errors keep the form open. Reloading or closing the browser tab shows the browser warning. A successful save clears the dirty state. Logo uploads remain separate and immediate.

### Business licence

The legal details, on the Details page, **Business licence** tab (`/p/{code}/settings/business`, `settings.manage`). It is the second tab.

```
┌ Business licence ────────────────────────────────────┐
│ Registered name [ Sandbay Resort Sdn Bhd ]           │
│ SSM registration no. [ 202301012345 ]                │
│ TIN (LHDN)  [ C1234567890 ]                          │
│ Licences    (table: issued by, number, expires,      │
│              print)                                  │
│                                [ Save business ]     │
└──────────────────────────────────────────────────────┘
```

- `SavePropertyBusiness` (`app/Actions/Property/`) saves the three fields and replaces the licences list in one transaction. A row with an id keeps it, a row without one is new, and a saved licence missing from the list is deleted. List order becomes `position`. It logs `property.updated` with the changed fields (`licences` for the table). A save that changes nothing is not logged. It returns false, without writing, for an id that is not the property's.
- Up to 20 licences. Issuer up to 150 and number up to 50 characters, both required. `expires_on` is optional.
- The empty table shows one row, "No licences yet.", and **Add licence** in the footer.
- The tab shares the labels of the [country profile](03-seed-property-defaults.md#country-profiles). Basics shows the same fields later as its Business details group.
- Printed documents read the registered name and the licences marked **Print** through [Printing settings](05-printing-settings.md).

### Logo

- A single-file `logo` media collection on Property, on the platform media disk (private). PNG or JPEG, up to 1 MB. SVG is refused because dompdf draws it poorly.
- The logo uploads as soon as a file is picked. It is not part of Save.
- `UploadPropertyLogo` replaces the file. `RemovePropertyLogo` deletes it. Each logs `property.updated` with `logo`.
- `settings.logo` streams the file to staff with `settings.manage`, for the browser previews. PDFs embed the file as a data URI, read from its disk.
- The staff navbar shows the uploaded logo in the square beside the property name. It falls back to the property's name initials when no logo exists or the image fails to load. `property.logo` streams the private logo to verified staff of that live property without requiring `settings.manage`. Upload and removal permissions stay unchanged. The guest registration screen can show it later. See [App shell](../design-system/09-app-shell.md).

| Route                                    | Name                                            |
| ---------------------------------------- | ----------------------------------------------- |
| `GET`, `PUT /p/{code}/settings/profile`  | `settings.profile`, `settings.profile.update`   |
| `GET`, `PUT /p/{code}/settings/business` | `settings.business`, `settings.business.update` |
| `POST`, `DELETE …/settings/logo`         | `settings.logo.store`, `settings.logo.destroy`  |
| `GET …/settings/logo`                    | `settings.logo`                                 |

## Rules

- Every domain table has `property_id`, and every query is scoped by it.
- The current property is resolved from the `{property:code}` route parameter by `EnsurePropertyAccess`, and must match `$user->property` (or an active impersonation session).
- `PropertyType` drives defaults (see [Seed property defaults](03-seed-property-defaults.md)).
- PMS routes require `Live` (see [The fence](../17-onboarding/02-wizard-steps.md#the-fence)).
- Suspended staff are redirected from property routes to `/p/{code}/suspended`, a read-only notice. Personal account routes remain available. After reactivation, the notice route redirects to the dashboard.

## Lessons from wastays

- `hotels` had 74 columns mixing identity, tax, AI, training and feature flags. Keep `properties` to identity and address. Operating settings go to [Property settings](02-property-settings.md).
- Tourism tax existed both as columns on `hotels` and as rows in `hotel_taxes`. Taxes live only in `taxes`.
- wastays had four identifiers: `unique_id`, `slug`, `public_id` (UUID) and `hotel_prefix` with a prefix history table. `unique_id` was issued with an advisory lock and `MAX + 1`. estatell keeps one `code` from a counter row per prefix.

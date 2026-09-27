# Terms documents and presets

## Purpose

Give every property ready-to-use registration terms, versioned and translated, and let some room types use different terms.

## Data model

```
terms_documents            id, property_id, name, is_default
                           partial unique: one is_default per property
registration_card_terms    id, terms_document_id, locale, version, body (markdown),
                           preset (TermsPreset, nullable), preset_version (nullable),
                           published_at, published_by
                           unique (terms_document_id, locale, version)
room_types.terms_document_id (nullable)
```

## Presets

- Markdown files in the repository: `resources/terms/{preset}/{locale}.md`, version in front-matter.
- `TermsPreset` enum: StandardHotel, Hostel, Resort, ServicedApartment, Homestay, with `path(locale)` and `version()`.
- Rendered with `Str::markdown()` and `html_input => strip`.
- A property gets a **copy**. When the app ships a newer preset version, settings show "A newer template is available, compare / apply". Nothing changes automatically.
- The UI states that presets are templates, not legal advice.

| Preset                       | Key clauses                                                                                                                        |
| ---------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| StandardHotel                | Check-in and check-out times, ID, damage, valuables, no smoking fine, visitors, deposit, early departure, PDPA notice, tourism tax |
| Hostel                       | Standard + lockers, quiet hours, shared-room conduct, minimum age, bed changes                                                     |
| Resort                       | Standard + boat schedules, weather, marine park fees, activity risk                                                                |
| ServicedApartment / Homestay | House rules, access cards, utilities, cleaning, occupancy limits                                                                   |

## Placeholders

`{{property_name}}`, `{{check_in_time}}`, `{{check_out_time}}`, `{{late_checkout_fee}}`, `{{smoking_fine}}`, `{{key_card_fee}}`, `{{security_deposit}}`, `{{tourism_tax_rate}}`.

- Allowed names live in the `TermsPlaceholder` enum. The Form Request rejects unknown placeholders.
- Placeholders are filled at signing and frozen in the card snapshot.

## Rules

- Property creation seeds one "Standard" default document, version 1, in English and Bahasa Malaysia, from the preset for the property type.
- Versions are never deleted. Signed cards reference them.
- The UI shows one document at first. Multiple documents and per-room-type assignment are available when needed.

## Actions

`PublishRegistrationTerms`, `ApplyTermsPreset`, `CreateTermsDocument`.

## Permissions

`registration-card.manage-terms`.

## Lessons from wastays

Terms were free text on `hotels`, copied into a `terms_snapshot` jsonb per card.

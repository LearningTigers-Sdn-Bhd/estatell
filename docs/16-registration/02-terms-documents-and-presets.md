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
room_types.terms_document_id (nullable, foreign key, null on delete)
```

- `room_types.terms_document_id` exists already. This item adds its foreign key.
- Versions count per document and language. Publishing makes version N+1.

## Presets

- Markdown files in the repository: `resources/terms/{preset}/{locale}.md`, version in front-matter (one `version: 1` line, read by the app without a YAML package).
- `TermsPreset` enum: StandardHotel, Hostel, Resort, ServicedApartment, Homestay, with `forPropertyType()`, `path(locale)` and `version(locale)`.
- All five presets ship in English and Bahasa Malaysia. The Bahasa Malaysia text is a first draft and needs a native reader before real use.
- Rendered with `Str::markdown()` and `html_input => strip`.
- A property gets a **copy**. When the app ships a newer preset version, the Terms tab shows "A newer template is available". Nothing changes automatically.
- The UI states that presets are templates, not legal advice.

| Preset                       | Key clauses                                                                                                                        |
| ---------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| StandardHotel                | Check-in and check-out times, ID, damage, valuables, no smoking fine, visitors, deposit, early departure, PDPA notice, tourism tax |
| Hostel                       | Standard + lockers, quiet hours, shared-room conduct, minimum age, bed changes                                                     |
| Resort                       | Standard + boat schedules, weather, marine park fees, activity risk                                                                |
| ServicedApartment / Homestay | House rules, access cards, utilities, cleaning, occupancy limits                                                                   |

## Placeholders

`{{property_name}}`, `{{check_in_time}}`, `{{check_out_time}}`, `{{security_deposit}}`, `{{tourism_tax_rate}}`.

| Placeholder        | Source                                                     |
| ------------------ | ---------------------------------------------------------- |
| `property_name`    | `properties.name`                                          |
| `check_in_time`    | `property_settings.check_in_time`                          |
| `check_out_time`   | `property_settings.check_out_time`                         |
| `security_deposit` | `property_settings.security_deposit_amount`                |
| `tourism_tax_rate` | The property's Tourism tax. Empty text when it is not set. |

- Allowed names live in the `TermsPlaceholder` enum. The Form Request rejects unknown placeholders.
- Fees such as a late check-out fee, a smoking fine or a key card fee have no setting. Hotels type the amount into the terms text. A placeholder can be added later with its own setting.
- Placeholders are filled at signing and frozen in the card snapshot. `TermsRenderer` (a Service) fills them and renders the markdown. The editor preview uses it now, and signing and the PDF use it later.

## Checklist items

Key clauses the guest ticks one by one before signing. They live in the terms markdown as task lists, usually under a `## I confirm that` heading. A text may have several task lists, in different sections. There is no extra table.

```md
## I confirm that

- [ ] I will check out by {{check_out_time}}.
- [ ] I accept the {{property_name}} PDPA notice.
```

- `TermsRenderer` returns the rendered body and the list of items. An item is a `- [ ]` line. Its position is its identity.
- Placeholders work in items and are filled at signing.
- A version has at most 10 items in total, across all sections. The Form Request rejects more.
- Each tick box shows in the section where the author wrote it, in the editor preview, on the guest screen and in the PDF. The guest screen does not collect them into one list.
- Every item is required. A version with no items needs only the signature.
- Each preset ships 3 to 5 items in English and Bahasa Malaysia (no-smoking fine, deposit, PDPA notice, tourism tax, and quiet hours for Hostel).
- The editor preview shows the items in place as read-only checkboxes. A hint says task-list lines become tick boxes.
- Items are part of the body, so the "same as latest version" refusal covers them.

## Rules

- Property creation seeds one "Standard" default document, version 1, in English and Bahasa Malaysia, from the preset for the property type. `SeedRegistrationTerms` is idempotent. A migration back-fills existing properties.
- Versions are never deleted or edited. Signed cards reference them.
- Publishing text that is the same as the latest version of that language is refused.
- A document is deleted only when it is not the default and no room type uses it. The signing flow adds a refusal for signed cards.
- The default document cannot be deleted. Another document becomes the default only through a later change, so there is no action for it now.
- The UI shows one document at first. Multiple documents and per-room-type assignment appear when a second document exists.

## Actions

`SeedRegistrationTerms`, `CreateTermsDocument` (takes the chosen preset, default the property type's), `PublishRegistrationTerms`, `ApplyTermsPreset` (copies a preset as a new version with `preset` and `preset_version` set), `DeleteTermsDocument`.

## Permissions

`registration-card.manage-terms` (Owner and Manager). The Terms tab and its routes need it. The tab is hidden for users without it.

## UI

The Terms tab is full width, in a three-column grid like the Layout tab. The editor takes two columns and the preview takes one.

```
Terms   [Document: Standard ⌄]  [+ Add document]    [Language: English ⌄]
┌──────────────────────────────────┬─────────────────────────┐
│ v3 · published 5 Oct · Standard  │ Preview                 │
│ template                         │ (placeholders filled)   │
│ ┌──────────────────────────────┐ │                         │
│ │ markdown editor              │ │                         │
│ └──────────────────────────────┘ │                         │
│ [Version history]  [Publish new version]                   │
└──────────────────────────────────┴─────────────────────────┘
 ⓘ A newer template is available.  [Preview] [Apply]
```

- **Language** is a Select menu with every property locale (`Locale::options()`), not tabs or a toggle. A language the document has no version for is listed as "Bahasa Malaysia (not published)". Choosing it opens the editor with the property type's preset text, as an unpublished draft with a note. Publishing makes version 1 in that language.
- The **document** Select appears only when a second document exists. **Add document** is always in the toolbar and opens a Dialog with a name field and a **Start from** Select of the five templates (the property type's template is picked first). The new document starts as a copy of that template in every language. With one document the toolbar shows Add document and the language Select.
- The open document and language are in the URL (`?document=` and `?locale=`), so a refresh and a shared link keep them. Changing either is a partial reload that sends only the editor data.
- **Version history** is a Sheet with a read-only list (version, date, who). **Use as starting point** copies an old version into the editor. It does not publish.
- **Apply** (and the "newer template" banner) calls `ApplyTermsPreset`. It makes a new version, so nothing is lost. **Preview** shows the new template text read only. There is no line-by-line compare.
- The preview is drawn in the browser from the editor text. The server sends the placeholder values. It is a small renderer in the app (headings, lists, bold, italic and tick boxes), so no markdown package is added. The server renders the final text with `Str::markdown()`.
- Each version is saved only by **Publish new version**. There is no autosave on this tab, because a published version cannot be edited.
- Routes are under `/p/{code}/settings/registration-card/terms` (see [Routing](../routing.md)).
- The room type form has a **Terms document** Select (Default, or one of the property's documents). It shows only when a second document exists.

## Lessons from wastays

Terms were free text on `hotels`, copied into a `terms_snapshot` jsonb per card.

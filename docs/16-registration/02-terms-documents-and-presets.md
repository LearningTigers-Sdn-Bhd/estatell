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

| Placeholder        | Source                                                                                                          |
| ------------------ | --------------------------------------------------------------------------------------------------------------- |
| `property_name`    | `properties.name`                                                                                               |
| `check_in_time`    | `property_settings.check_in_time`                                                                               |
| `check_out_time`   | `property_settings.check_out_time`                                                                              |
| `security_deposit` | `property_settings.security_deposit_amount`                                                                     |
| `tourism_tax_rate` | The property's Tourism tax. Uses "the applicable amount" when no enabled rate is set, without inventing a rate. |

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

- Property creation seeds one "Standard" default document, version 1, in English and Bahasa Malaysia, from the preset for the property type. `SeedRegistrationTerms` is idempotent. A migration back-fills existing properties. Properties outside Malaysia get the English text only, without the tourism tax and PDPA items ([Country profiles](../02-property/03-seed-property-defaults.md#country-profiles)).
- Versions are never deleted or edited. Signed cards reference them.
- Publishing text that is the same as the latest version of that language is refused.
- A document is deleted only when it is not the default and no room type uses it. The signing flow adds a refusal for signed cards.
- The default document cannot be deleted. Another document becomes the default only through a later change, so there is no action for it now.
- The room type form shows its Terms document Select only when a second document exists.

## Actions

`SeedRegistrationTerms`, `CreateTermsDocument` (takes the chosen preset, default the property type's), `PublishRegistrationTerms`, `ApplyTermsPreset` (copies a preset as a new version with `preset` and `preset_version` set), `DeleteTermsDocument`.

## Permissions

`registration-card.manage-terms` (Owner and Manager). The Terms tab and its routes need it. The tab is hidden for users without it.

## UI

Two pages, like Room types: a list of documents on the Terms tab, and an editor page per document. The list replaced one tab that held the document and language Selects, the editor and every action (2026-11-27).

### Terms tab (list)

```
┌──────────────────────────────────────────────────────────────────┐
│ Terms                                               [+ New doc]  │
│ What guests agree to when signing. Each change is a new version. │
│ Search documents [                                     ]         │
│ ┌──────────────────────────────────────────────────────────────┐ │
│ │ Name             Languages                 Room types  Last… │ │
│ │ Standard Default English v3 · BM v2                 3  12 Nov›│ │
│ │ Chalet rules     English v1 · BM Draft              1  02 Nov›│ │
│ └──────────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────┘
```

- A `rounded-xl` table in the Workspace panel, no rail. Columns: Name (with a **Default** badge), Languages (latest version per language, or **Draft**), Room types (how many use it), Last published. The whole row opens the document page.
- Search sits on its own row under the heading and filters by name in the browser.
- **New document** opens a Dialog with a name field and a **Start from** Select of the five templates (the property type's template is picked first). The new document starts as a copy of that template in every language, and its page opens.

### Document page (editor)

`GET settings.registration-card.terms.documents.edit` at `/settings/registration-card/terms/documents/{document}?locale=en`. Breadcrumbs: Settings › Registration card › Terms › {document} › {language}.

```
← Standard [Default]                                      [⋯ More actions]
  Used by 3 room types.                                     Version history
[English] [Bahasa Malaysia  Draft]                          Delete document
┌──────────────────────────────────────────────┬─────────────────────────┐
│ Terms text                                   │ Preview                 │
│ v3 published 12 Nov 2026 by Aina             │ (placeholders filled)   │
│ ┌──────────────────────────────────────────┐ │                         │
│ │ markdown editor                          │ │                         │
│ └──────────────────────────────────────────┘ │                         │
│ ⓘ A newer template is available. [Preview] [Apply]                     │
└──────────────────────────────────────────────┴─────────────────────────┘
              [ Unsaved changes   Discard   Publish v4 ]   ← Save bar
```

- A fixed-height Workspace with the preview in the 28rem rail.
- **Languages are tabs**, one per `Locale` case, each its own URL (`?locale=`). A language with no version shows a **Draft** badge on its tab. It opens with the property type's preset text, as an unpublished draft. Publishing makes version 1 in that language.
- **Publish** is in the floating Save bar, shown while the text has changed or the language is a draft. Its button names the next version ("Publish v4"). Discard restores the latest version. Leaving with unsaved text asks first.
- **More actions** holds **Version history** and **Delete document**. Delete shows only when the document is not the default and no room type uses it. After delete, the list opens.
- **Version history** is a Sheet with a read-only list (version, date, who). **Use as starting point** copies an old version into the editor. It does not publish.
- **Apply** (and the "newer template" banner) calls `ApplyTermsPreset`. It makes a new version, so nothing is lost. **Preview** shows the new template text read only. There is no line-by-line compare.
- The preview is drawn in the browser from the editor text. The server sends the placeholder values. It is a small renderer in the app (headings, lists, bold, italic and tick boxes), so no markdown package is added. The server renders the final text with `Str::markdown()`.
- Each version is saved only by Publish. There is no autosave, because a published version cannot be edited.
- Publish and Apply return to the document page in the same language.

### Shared

- Routes are under `/p/{code}/settings/registration-card/terms` (see [Routing](../routing.md)).
- The room type form has a **Terms document** Select (Default, or one of the property's documents). It shows only when a second document exists.

## Lessons from wastays

Terms were free text on `hotels`, copied into a `terms_snapshot` jsonb per card.

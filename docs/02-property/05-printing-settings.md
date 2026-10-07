# Printing settings

## Purpose

How every PDF of a property looks: the letterhead, the font, the font size and the footer. Reports, receipts, AR invoices, AR statements and the aging report all use it.

## Data model

```
print_settings              one row per property
  id, property_id (unique FK)
  font                      PdfFont, default standard
  font_size                 PdfFontSize, default medium
  show_logo                 bool, default true
  show_address              bool, default true
  show_contact              bool, default true
  show_registration_number  bool, default true
  show_tin                  bool, default false
  footer_text               varchar(500), null
  timestamps

print_setting_taxes         the tax numbers the header shows
  id, print_setting_id (FK, cascade), tax_id (FK, restrict)
  unique (print_setting_id, tax_id)
```

- A 1:1 table with typed columns, like `property_settings`. New print options are new columns.
- **The table stores switches, never values.** The header reads the values where they live:

| Header line           | Read from                                                           |
| --------------------- | ------------------------------------------------------------------- |
| Logo                  | The `logo` media collection on Property                             |
| Address               | `properties.address_line1`, `address_line2`, `postal_code`, `city`  |
| Phone and email       | `properties.contact_phone`, `contact_email`, on one line with a `·` |
| Business registration | `properties.registration_number`                                    |
| TIN                   | `properties.tin`                                                    |
| Tax numbers           | `taxes.registration_number` of each tax in `print_setting_taxes`    |

- `SeedPropertyDefaults` creates the row with the defaults above and no tax rows. A back-fill migration creates it for existing properties. Code never checks for a missing row.
- `footer_text` is trimmed. An empty text is stored as null (CHECK, like `taxes.registration_number`).

## Enums

- `PdfFont`: Standard ("Standard (Latin, Malay)", DejaVu Sans), Chinese ("Chinese", Noto Sans SC, which also covers Latin). `fontFamily()` returns the CSS family.
- `PdfFontSize`: Small (8 pt), Medium (9 pt), Large (10.5 pt). `points()` returns the body size. Small text is 1 pt less, headings 2 pt more and the title 7 pt more.

## Fonts

- dompdf needs a TrueType file for each font. DejaVu Sans is built in, and Noto Sans SC is already in `resources/fonts/` for the registration card. No new font file is added.
- **Chinese text always prints.** When any text in a document needs Noto Sans SC (the same check as `RegistrationCardDocument::needsCjk()`), the whole document uses it, whatever the setting. A guest name in Chinese never prints as empty boxes.
- Thai, Tamil and Jawi are not offered. dompdf has no complex-script shaping, so they print wrongly. Add them only after a test PDF proves otherwise.

## Logo

- A single-file `logo` media collection on Property, on the private media disk. PNG or JPEG, up to 1 MB. SVG is refused because dompdf draws it poorly.
- `UploadPropertyLogo` replaces the file. `RemovePropertyLogo` deletes it.
- PDFs read the file from disk. The browser preview reads it from `settings.printing.logo`, which streams it to staff with `settings.manage`.
- With no logo, the Show logo switch is disabled and the header starts with the property name.

## Tax numbers

- The Header tab lists every tax of the property in the Taxes tab order. A tax with no `registration_number` shows "No number" with a link to the Taxes tab, and its switch is disabled.
- A tax that is disabled still prints its number when picked. A registration number stays valid while a tax is switched off.
- When printing, a picked tax whose number was removed later is skipped.

## Legal fields

The switches control only the letterhead. A document that must show a number prints it in its own body, whatever the switches say. For example, an AR invoice shows the SST number next to the SST total.

## Letterhead and footer

- `resources/views/pdf/partials/letterhead.blade.php` draws the header on the first page: the logo on the left, then the property name and the lines that are switched on.
- The shared `pdf/layout.blade.php` reads the font, the font size and the orientation, and draws `footer_text` at the foot of every page.
- `PrintProfile` (`app/Services/Property/`) builds what the layout needs from one query: the settings, the header lines and the logo path. Documents pass it to the view.
- The registration card keeps its own layout and font choice. It does not use the letterhead.

## UI

**Printing** is its own Settings sidebar item (`settings.manage`, icon `printer`), below Registration card. It has two tabs with their own URLs:

| Tab     | URL                                  | Content                                 |
| ------- | ------------------------------------ | --------------------------------------- |
| General | `/p/{code}/settings/printing`        | Font, font size, footer text            |
| Header  | `/p/{code}/settings/printing/header` | Logo, header line switches, tax numbers |

Report column layouts are not here. They are a Sheet on each report page. See [Core reports](../22-reports/01-core-reports.md#layout-sheet).

Both tabs use the three-column grid of the registration card Layout tab: the form takes two columns and a sticky preview takes one.

```
┌ General ──────────────────────────────────┬ Preview (sticky) ──────┐
│ Font        [ Standard (Latin, Malay) ▾ ] │ Sandbay Resort         │
│             Chinese text always prints    │ Tan Wei Ming · 陈美玲   │
│             in Noto Sans SC.              │ RM 1,250.00            │
│ Font size   ( ) Small (•) Medium ( ) Large│ ────────────────────── │
│ Footer text [ Thank you for staying…    ] │ Thank you for staying… │
│                                           │ [Open sample PDF ↗]    │
└───────────────────────────────────────────┴────────────────────────┘

┌ Header ───────────────────────────────────┬ Preview (sticky) ──────┐
│ Logo        [img] [Replace] [Remove]      │ [LOGO] Sandbay Resort  │
│ ● Show logo                               │ Lot 12, Jalan Pantai…  │
│ ● Address                                 │ +60 88 123 456 · stay@ │
│ ● Phone and email                         │ Reg. 202301012345      │
│ ● Business registration no.               │ SST W10-1808-32000123  │
│ ○ TIN                         Not set ↗   │ TTX 12-3456789         │
│ Tax numbers                               │                        │
│   ● Service Tax (SST)  W10-1808-32000123  │ [Open sample PDF ↗]    │
│   ● Tourism tax (TTX)  12-3456789         │                        │
│   ○ Service charge     No number ↗        │                        │
└───────────────────────────────────────────┴────────────────────────┘
```

- An empty property value shows "Not set" with a link to the Details page, and its switch is disabled.
- There is no Save button. A switch, select or radio saves by itself 300 ms after the last change. The footer text saves on blur. The header shows "Saving…" and then "Changes save automatically." A refused save shows an error toast and the form snaps back to the server copy.
- The preview is drawn in the browser from the form state. The server sends the property values, the tax numbers and the logo URL. Browser fonts differ from dompdf fonts, so the preview is close but not exact.
- **Open sample PDF** opens `settings.printing.sample` in a new tab: the real dompdf output of the saved settings, with a sample report table.

## Actions

- `UpdatePrintSettings`: font, font size and footer text.
- `UpdatePrintHeader`: the five switches and the tax list, synced in one transaction. Refuses a tax of another property.
- `UploadPropertyLogo`, `RemovePropertyLogo`.

Each logs `print_settings.updated` when something changed, with the changed fields.

## Routes

All need `settings.manage`. See [Routing](../routing.md).

| Route                                    | Name                                            |
| ---------------------------------------- | ----------------------------------------------- |
| `GET`, `PUT /p/{code}/settings/printing` | `settings.printing.general`, `.update`          |
| `GET`, `PUT …/printing/header`           | `settings.printing.header`, `.header.update`    |
| `POST`, `DELETE …/printing/logo`         | `settings.printing.logo.store`, `.logo.destroy` |
| `GET …/printing/logo`                    | `settings.printing.logo`                        |
| `GET …/printing/sample`                  | `settings.printing.sample`                      |

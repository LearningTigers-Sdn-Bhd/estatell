# Printing settings

## Purpose

How every PDF of a property looks: the letterhead, the font style, the font size and the footer. Reports, receipts, AR invoices, AR statements and the aging report all use it. The Documents tab also chooses what the booking summary, the folio invoice and the AR invoice show.

## Data model

```
print_settings              one row per property
  id, property_id (unique FK)
  font                      PdfFont, default office
  font_size                 PdfFontSize, default medium
  show_logo                 bool, default true
  show_address              bool, default true
  show_contact              bool, default true
  show_registration_number  bool, default true
  show_tin                  bool, default false
  footer_text               varchar(500), null
  show_unit_on_lines        bool, default true    (Documents tab)
  nightly_breakdown         bool, default false   (Documents tab)
  tax_breakdown             bool, default true    (Documents tab)
  show_amount_in_words      bool, default false   (Documents tab)
  show_cancellation_policy  bool, default true    (Documents tab)
  show_signature_lines      bool, default false   (Documents tab)
  bank_details              varchar(500), null    (Documents tab)
  timestamps

print_setting_taxes         the tax numbers the header shows
  id, print_setting_id (FK, cascade), tax_id (FK, restrict)
  unique (print_setting_id, tax_id)
```

- A 1:1 table with typed columns, like `property_settings`. New print options are new columns.
- **The table stores switches, never values.** The header reads the values where they live:

| Header line           | Read from                                                                            |
| --------------------- | ------------------------------------------------------------------------------------ |
| Logo                  | The `logo` media collection on Property                                              |
| Address               | `properties.address_line1`, `address_line2`, `postal_code`, `city`, the country name |
| Phone and email       | `properties.contact_phone`, `contact_email`, on one line with a `·`                  |
| Business registration | `properties.registration_number`                                                     |
| TIN                   | `properties.tin`                                                                     |
| Tax numbers           | `taxes.registration_number` of each tax in `print_setting_taxes`                     |

- `SeedPropertyDefaults` creates the row with the defaults above and no tax rows. A back-fill migration creates it for existing properties, and `PropertyFactory` creates it in tests. Code that prints never checks for a missing row. The printing routes are a 404 before Basics, when the row does not exist yet.
- `footer_text` and `bank_details` are trimmed. An empty text is stored as null (CHECK, like `taxes.registration_number`).
- `bank_details` is the one value the table stores: the property has no bank account record, and the text is only ever printed.

## Enums

- `PdfFont`: Office, Modern, Fancy, Handwritten. `headingFamily()` and `bodyFamily()` return the Latin fonts, `stack()` adds the fallbacks, `fontFaces()` lists the files, and `options()` sends both families for the style cards. The first font setting (Standard, Chinese) became Office.
- `PdfFontSize`: Small (8 pt), Medium (9 pt), Large (10.5 pt). `points()` returns the body size. Small text is 1 pt less, headings 2 pt more and the title 7 pt more.

## Fonts

The font style sets a heading font (the property name, the document title and section headings) and a body font (text and tables) for Latin text. English and Chinese are covered:

| Style           | Headings              | Body   |
| --------------- | --------------------- | ------ |
| Office, default | Arimo Bold            | Arimo  |
| Modern          | Inter Bold            | Inter  |
| Fancy           | Playfair Display Bold | Lora   |
| Handwritten     | Caveat Bold           | Nunito |

- **Chinese prints in every style.** Each font-family list ends with Noto Sans SC, Noto Sans TC and DejaVu Sans. dompdf draws a character the style's fonts lack with the next font in the list, one character at a time (tested: a Latin and Chinese line embeds both fonts). A Latin-only document does not embed the Chinese fonts.
- Chinese has no bold file. Bold Chinese text prints with the regular file.
- Fancy and Handwritten change only the headings' look; amounts stay in the body font.
- The TTF files are in `resources/fonts/` (Google Fonts, OFL licence, about 21 MB with the full Noto Sans SC and TC). `PdfFont::fontFaces()` lists the files the layout declares for a style.
- The browser preview loads the same Latin files through the Vite fonts plugin (`local()`, not preloaded), so it downloads them only on the Printing page. It uses the system Chinese font.
- `PrintedPdf` (`app/Services/Property/`) renders every PDF that uses the shared layout and sets the paper, the orientation and font subsetting. Controllers call it instead of repeating `Pdf::loadView()->setPaper()->setOption()`.
- Thai, Tamil, Jawi and other scripts that need shaping are not covered. dompdf has no complex-script shaping. Covering them needs a Chromium-based PDF engine (for example a Gotenberg container), a decision for later.

## Logo

- The logo is part of the property profile, not a print setting. It is uploaded on the Details page, Profile tab. See [Properties](01-properties.md#profile).
- PDFs embed the file as a data URI, read from its disk. The browser preview reads it from `settings.logo`.
- With no logo, the Show logo switch is disabled with a "No logo" link to the Profile tab, and the header starts with the property name.

## Tax numbers

- The Header tab lists every tax of the property in the Taxes tab order. A tax with no `registration_number` shows "No number" with a link to the Taxes tab, and its switch is disabled.
- A tax that is disabled still prints its number when picked. A registration number stays valid while a tax is switched off.
- When printing, a picked tax whose number was removed later is skipped.

## Legal fields

The Header switches control only the letterhead, and the Documents switches only the layout and the optional sections. A document that must show a fact prints it in its own body, whatever the switches say. For example, an AR invoice and a folio invoice show the SST number next to the SST total. Always printed on an invoice: the number, the date, Bill to, the tax amounts with the tax registration number, and the total.

## Letterhead and footer

- `resources/views/pdf/partials/letterhead.blade.php` draws the header on the first page: the logo on the left, then the property name and the lines that are switched on.
- The shared `pdf/layout.blade.php` reads the font, the font size and the orientation, and draws `footer_text` at the foot of every page.
- `PrintProfile` (`app/Services/Property/`) builds a `PrintLayout` from the settings, the property, its taxes and its logo: the font, the size, the header lines, the footer and the logo as a data URI (so a logo on R2 prints like a local one). `PrintedPdf` passes it to the view as `$print`.
- A view draws the letterhead with `@component('pdf.partials.letterhead', ['print' => $print])`. The component's slot is the document heading on the right (for example "Receipt" and its number). The voucher draws it on each copy.
- `PrintProfile` replaces `App\Services\Folio\Letterhead`. The receipt, voucher, AR invoice, AR receipt, AR statement and aging views drop their own property block and use the partial, so the header follows the switches. TIN is off by default, so it leaves these documents until it is switched on.
- The layout draws the letterhead and the footer only when the view gets a `PrintProfile`. Two documents get none and keep their own look:
    - The registration card keeps its own font choice. It does not use the letterhead.
    - The temporary password slips are cut out and handed to staff, so a letterhead and a footer do not fit.

## Documents tab

What the customer documents show. One set of switches: each applies to every document where it fits. There is no column-count setting: a document's info strip leaves out empty cells and shares the width between the rest (2 to 4 cells).

| Switch                                           | Booking summary | Folio invoice | AR invoice | Default |
| ------------------------------------------------ | --------------- | ------------- | ---------- | ------- |
| Room and guest on lines (`show_unit_on_lines`)   | ✓               | ✓             | ✓          | On      |
| Nightly breakdown (`nightly_breakdown`)          | ✓               | ✓             | ✓          | Off     |
| Tax breakdown (`tax_breakdown`)                  | ✓               | ✓             | ✓          | On      |
| Amount in words (`show_amount_in_words`)         | ✓               | ✓             | ✓          | Off     |
| Cancellation policy (`show_cancellation_policy`) | ✓               | –             | –          | On      |
| Signature lines (`show_signature_lines`)         | –               | ✓             | –          | Off     |
| Payment details (`bank_details`, text)           | ✓               | –             | ✓          | Empty   |

- **Room and guest on lines**: charge rows are grouped under "Room 103 · Tan Wei Ming", from the line source (a child takes its parent's). Off: one list by date with a **Room** column. See [Room column](#room-column).
- **Nightly breakdown**: off collapses a unit's room lines with the same description and amount into "Room, 3 nights × 250.00" with the date range (inclusions and discounts the same way). On: one row per night.
- **Tax breakdown**: on sums the tax lines per tax ("Service tax 8%", "Tourism tax"). Off: one "Tax" total. The tax registration number still prints next to the tax total (see [Legal fields](#legal-fields)).
- **Amount in words**: English words under the total, for example "Ringgit Malaysia one thousand five hundred and four only", or "… and fifty sen only" when there are cents. See [Amount in words](#amount-in-words).
- **Empty values never print**, whatever the switches: no "-", no "0.00" payment line, no country-only address, no empty strip cell.
- The voucher, the receipts, the AR statement and the reports do not read these switches.

```
┌ Documents ────────────────────────────────┬ Preview (sticky) ──────┐
│ Lines                                     │ Booking summary        │
│ ● Room and guest on lines                 │ BILL TO   STAY         │
│ ○ Nightly breakdown                       │ Acme      12–15 Oct    │
│ ● Tax breakdown                           │ Room 103 · Tan Wei Ming│
│ ○ Amount in words                         │  Room, 3 nights 750.00 │
│ Sections                                  │  Service tax 8%  60.00 │
│ ● Cancellation policy  (booking summary)  │  Total MYR      810.00 │
│ ○ Signature lines      (folio invoice)    │ Cancellation policy    │
│ Payment details                           │ Free until 2 days…     │
│ [ Maybank 5141 2233 4455 · Sandbay …    ] │ [Open sample PDF ↗]    │
└───────────────────────────────────────────┴────────────────────────┘
```

- Each switch says which documents it changes in its description.
- The preview draws a sample booking summary from the form state. **Open sample PDF** opens `settings.printing.sample?document=booking-summary`: the real dompdf output with sample data.
- The sample PDF is `pdf/document-sample.blade.php`. It draws the letterhead, a sample info strip, the shared lines partial, the cancellation policy text and the payment details. Its sample rows go through the same line grouper as the real documents.

### Line grouper

`DocumentLines` (`app/Services/Folio/`) turns folio rows into what a customer document prints. The booking summary, the folio invoice and the AR invoice all use it, so the three print the same lines the same way.

- **Input**: plain `DocumentRow` objects, not models. The booking summary sends posted lines and Scheduled rows; the folio invoice and the AR invoice send posted lines only. Each row carries its kind, description, date, quantity, unit amount, amount, tax, unit id, line id and reversal link.
- **Output**: `DocumentLineGroups`: the groups (heading and rows), the tax summary, the charges subtotal and the total. It reads nothing from the database and computes nothing a folio does not already hold.

```
rows ─▶ DocumentLines::group(rows, PrintSetting) ─▶ DocumentLineGroups
         1. drop payments, and drop a line together with its reversal
         2. group by unit (switch on), or one list by date (off)
         3. merge equal room nights, inclusions and discounts (breakdown off)
         4. sum taxes per tax (switch on), or one "Tax" total
```

- **Unit of a line**: from its source, through `LineSourceUnits` (`app/Services/Folio/`): a unit night and an add-on point to their unit, a reservation unit is its own, and a child line takes its parent's. This lookup moved out of `FolioStatement`, which now uses the same service. The caller resolves the unit before it builds the rows.
- **Group order**: units in the order the caller gives, then "Other charges" for lines with no unit, last. With grouping off, rows sort by date, then room, then posting order.
- **Room label**: each output row carries the room label of its unit (`DocumentLineRow::$room`), so the template looks nothing up.
- **Merging**: rows of one unit with the same kind, description and unit amount on consecutive dates become one row, "Room, 3 nights × 250.00", with the date range. A price change or a gap in dates starts a new row. Only nightly rows merge (room nights, inclusions, their child lines, Per night and Per guest night add-ons); other charges print one row per line.
- **Transfers**: a transfer is a reversal plus a copy, so step 1 drops the pair and the copy prints once.
- The partial `pdf/partials/document-lines.blade.php` draws the groups, the tax summary, the total and the amount in words. The three documents and the sample include it.

### Room column

With **Room and guest on lines** off, the lines are one list by date. A Room column keeps each line tied to its room, as on a group bill.

| Case                                    | Room column                                   |
| --------------------------------------- | --------------------------------------------- |
| More than one unit on the document      | Shown, room number only (no guest name)       |
| One unit                                | Hidden: the room is already in the info strip |
| A line with no unit (reservation level) | Empty cell                                    |
| A unit with no room assigned yet        | The room type name                            |

Merging still works per unit, so two rooms at different prices give two merged rows:

```
Date        Room  Description                  Amount
12–14 Oct   103   Room, 3 nights × 250.00      750.00
12–14 Oct   104   Room, 3 nights × 230.00      690.00
13 Oct      103   Island hopping trip          360.00
```

The browser preview and the sample PDF show the column the same way.

### Amount in words

- `AmountInWords` (`app/Services/Property/`) uses PHP `intl`: `NumberFormatter` with the `en_GB` locale and the `%spellout-numbering-verbose` rule set, which gives "one thousand five hundred and four". No new package.
- It splits the amount into the major and minor parts and spells each one: "{major name} {words} and {words} {minor name} only". With no cents the "and … sen" part is left out. With cents only (0.05) it prints "Five sen only", without the currency name.
- The currency words come from `Currency::wordsName()` ("Ringgit Malaysia") and `Currency::minorUnitWords()` ("sen").

## UI

**Printing** is its own Settings sidebar item (`settings.manage`, icon `printer`), below Registration card. It has three tabs with their own URLs:

| Tab       | URL                                     | Content                                    |
| --------- | --------------------------------------- | ------------------------------------------ |
| General   | `/p/{code}/settings/printing`           | Font style, font size, footer text         |
| Header    | `/p/{code}/settings/printing/header`    | Header line switches, tax numbers          |
| Documents | `/p/{code}/settings/printing/documents` | Document switches and payment details text |

Report column layouts are not here. They are a Sheet on each report page. See [Core reports](../22-reports/01-core-reports.md#layout-sheet).

All tabs use the three-column grid of the registration card Layout tab: the form takes two columns and a sticky preview takes one.

```
┌ General ──────────────────────────────────┬ Preview (sticky) ──────┐
│ Font style                                │ Sandbay Resort         │
│ ┌ Office ──────(•)┐ ┌ Modern ───────( )┐  │ Tan Wei Ming · 陈美玲   │
│ │ Sandbay Resort  │ │ Sandbay Resort   │  │ RM 1,250.00            │
│ │ 陈美玲 RM 1,250  │ │ 陈美玲 RM 1,250   │  │                        │
│ └─────────────────┘ └──────────────────┘  │                        │
│ ┌ Fancy ───────( )┐ ┌ Handwritten ──( )┐  │                        │
│ └─────────────────┘ └──────────────────┘  │                        │
│ Font size                                 │                        │
│ ┌▼A Small ( )┐┌T Medium (•)┐┌▲A Large ( )┐│                        │
│ │8 pt body   ││9 pt body   ││10.5 pt body││                        │
│ └────────────┘└────────────┘└────────────┘│                        │
│ Footer text [ Thank you for staying…    ] │ Thank you for staying… │
│                                           │ [Open sample PDF ↗]    │
└───────────────────────────────────────────┴────────────────────────┘

┌ Header ───────────────────────────────────┬ Preview (sticky) ──────┐
│ ● Show logo                               │ [LOGO] Sandbay Resort  │
│ ● Address                                 │ Lot 12, Jalan Pantai…  │
│ ● Phone and email                         │ +60 88 123 456 · stay@ │
│ ● Business registration no.               │ Reg. 202301012345      │
│ ○ TIN                         Not set ↗   │ SST W10-1808-32000123  │
│ Tax numbers                               │ TTX 12-3456789         │
│   ● Service Tax (SST)  W10-1808-32000123  │                        │
│   ● Tourism tax (TTX)  12-3456789         │ [Open sample PDF ↗]    │
│   ○ Service charge     No number ↗        │                        │
└───────────────────────────────────────────┴────────────────────────┘
```

- An empty property value or a missing logo shows "Not set" (or "No logo") with a link to the Details page, Profile tab, and its switch is disabled.
- There is no Save button. A switch, select or radio saves by itself 300 ms after the last change. The footer text saves on blur. The header shows "Saving…" and then "Changes save automatically." A refused save shows an error toast and the form snaps back to the server copy. The `use-auto-save` hook (`resources/js/hooks/`) does this, and later auto-saving forms reuse it.
- The preview is drawn in the browser from the form state. The server sends the property values, the tax numbers and the logo URL. Browser fonts differ from dompdf fonts, so the preview is close but not exact.
- **Open sample PDF** opens `settings.printing.sample` in a new tab: the real dompdf output of the saved settings, with a sample report table.

## Actions

- `UpdatePrintSettings`: font style, font size and footer text.
- `UpdatePrintHeader`: the five switches and the tax list, synced in one transaction. Refuses a tax of another property.
- `UpdatePrintDocuments`: the six document switches and `bank_details` (trimmed, empty stored as null). `UpdatePrintDocumentsRequest` validates six booleans and `bank_details` as nullable text of 500 characters at most.

Each logs `print_settings.updated` when something changed, with the changed fields.

## Routes

All need `settings.manage`. See [Routing](../routing.md).

| Route                                    | Name                                                                           |
| ---------------------------------------- | ------------------------------------------------------------------------------ |
| `GET`, `PUT /p/{code}/settings/printing` | `settings.printing.general`, `.update`                                         |
| `GET`, `PUT …/printing/header`           | `settings.printing.header`, `.header.update`                                   |
| `GET`, `PUT …/printing/documents`        | `settings.printing.documents`, `.documents.update`                             |
| `GET …/printing/sample`                  | `settings.printing.sample` (`?document=booking-summary` for the Documents tab) |

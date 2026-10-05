# Registration card signing

## Purpose

Capture guest details, ID and signature at check-in, at the desk or remotely.

## Build order

| Step | Commit                                                | Delivers                                                                                                    |
| ---- | ----------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| 1    | `feat(registration): add the desk signing flow`       | Card table, Actions, staff card page, signature pad, checklist ticks, void, Malay labels                    |
| 1b   | `feat(registration): hand the card to the guest`      | Read-only staff page, guest layout and guest screen, Agree to all, inline pad, guest walk, thank-you screen |
| 2    | `feat(registration): render the signed card as a PDF` | PDF, signature and PDF media, Download button, blank form to print, CJK and symbol fonts                    |
| 3    | `feat(registration): sign the card remotely`          | Signed link, the step 1b guest screen behind it, ID upload, QR code on the staff page                       |
| –    | `feat(registration): pair a signing tablet`           | Parked. See [Signing tablet (parked)](#signing-tablet-parked)                                               |

## Data model

```
registration_cards    id, property_id, unit_guest_id, number, status (RegistrationCardStatus),
                      identity_document_id (nullable), terms_id, vehicle_plate (nullable), signed_at, signer_name,
                      void_reason (nullable), voided_at (nullable), snapshot (jsonb, frozen at signing), timestamps
```

- `vehicle_plate` belongs to the stay, not to the guest, so it lives on the card.
- `property_id` scopes every query. `unit_guest_id` has a foreign key (restrict). `terms_id` has a foreign key to `registration_card_terms` (restrict), so signed terms can never be removed. `identity_document_id` has a foreign key (null on delete).
- A partial unique index allows one non-void card per `unit_guest_id`.
- `number` comes from `DocumentSequence` with `DocumentType::RegistrationCard`.
- The signature PNG and the rendered PDF are media files on the card (single-file collections `signature` and `pdf`).

## Enums

`RegistrationCardStatus`: Draft, Signed, Void. `canTransitionTo()`: Draft goes to Signed or Void, Signed goes to Void, Void goes nowhere.

## Flow

1. `PrepareRegistrationCard` finds or creates the draft card and pre-fills guest, ID document and stay details.
2. Staff check the card on the staff page and, if needed, pick the ID document and type the vehicle plate (`SaveRegistrationCardDraft`).
3. Hand to guest:
    - This device: staff open the guest screen. The guest checks the details, ticks the terms, signs and taps Sign card (`SignRegistrationCard`).
    - Guest's phone (step 3): the staff page shows a QR code of the signed link. `SendRegistrationCardLink` also emails it. The link lasts 7 days and has a Resend button. The guest completes fields, uploads ID and signs on the same guest screen.
4. `CheckInUnit` (module 18) checks cards against `registration_card_scope` (PrimaryOnly, or EveryAdult, which skips a guest known to be under 18 on arrival) when `card_required_for_check_in` is on. Users with `front-desk.check-in-without-card` can override. The override is logged. `RegistrationCardRequirement` (a Service, step 1) tells it which guests still need a card.

## Actions

| Action                      | Does                                                                                                                                                                                    |
| --------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `PrepareRegistrationCard`   | Finds or creates the draft for a unit guest.                                                                                                                                            |
| `SaveRegistrationCardDraft` | Sets `identity_document_id` (an existing document, or a new one through `AddIdentityDocument`) and `vehicle_plate` on a draft. Refuses a card that is not a draft.                      |
| `SignRegistrationCard`      | Checks the checklist and the signature, updates the guest with `UpdateGuest`, sets `guests.preferred_locale`, stores the signature, freezes the snapshot and sets the status to Signed. |
| `VoidRegistrationCard`      | Voids a Draft or Signed card with a reason and logs it.                                                                                                                                 |

`SignRegistrationCardRequest` checks the Required fields by the Layout tab, so errors show on the field. Expected refusals (already signed, closed unit, missing tick, bad signature, language with no terms) return false, not an HTTP error. The controller shows a toast.

## Routes

Routes are in `routes/registration.php`. All need `registration-card.sign`, except void.

| Method and URL                           | Name                  | Does                                                                                        |
| ---------------------------------------- | --------------------- | ------------------------------------------------------------------------------------------- |
| `GET /p/{code}/registration/{unitGuest}` | `registration.show`   | Staff page                                                                                  |
| `PUT …/{unitGuest}`                      | `registration.update` | Saves the ID document and vehicle plate on the draft                                        |
| `GET …/{unitGuest}/guest`                | `registration.guest`  | Guest screen, with `?locale=`                                                               |
| `POST …/{unitGuest}/sign`                | `registration.sign`   | Signs. Success goes to `registration.done`, a refusal back to the guest screen with a toast |
| `GET …/{unitGuest}/done`                 | `registration.done`   | Thank-you screen                                                                            |
| `GET …/{unitGuest}/pdf`                  | `registration.pdf`    | Downloads the signed card as a PDF (rendered on first download, then the stored file)       |
| `GET …/{unitGuest}/blank`                | `registration.blank`  | Prints the draft as a PDF without a signature, with `?locale=`                              |
| `POST …/{unitGuest}/void`                | `registration.void`   | Voids, needs `registration-card.void`                                                       |

## Staff card page

`pages/registration/card.tsx`, in the app layout. The guest row on the reservation page links to it. Staff check the card here. Nobody signs here.

```
┌ Aisyah Rahman · Room 204 · RC-0012   [Draft] ────────────────┐
│ Details (read-only)                 │ Hand to guest          │
│ ID document  [MyKad •••• 1234 ▾]    │  This device           │
│ Vehicle plate [WXY 1234]   [Save]   │  Guest's phone (QR)    │ (step 3)
│                                     │ Print blank form       │ (step 2)
│                                     │ Void                   │
└─────────────────────────────────────┴────────────────────────┘
```

- The details are the visible fields and the stay details, read-only. Only the ID document and the vehicle plate can be edited here, and only on a draft.
- "This device" opens `registration.guest` in the same tab.
- A signed card shows what was frozen at signing and Void. Hand to guest is hidden.
- A unit that is cancelled, a no-show or checked out has no card, and neither has a guest who was merged or anonymized.

## Guest screen

`pages/registration/guest.tsx`, in a new bare `layouts/guest-layout.tsx`: the property name, no sidebar, no breadcrumbs, no staff actions. Step 3 shows the same page behind the signed link.

```
┌────────────────────────────────────────────────────────┐
│ Welcome, Aisyah · Guest 1 of 3             [English ▾] │
│ ┌ Your stay ─────────────────────────────── RC-0012 ┐  │
│ │ CHECK-IN         3 nights          CHECK-OUT      │  │
│ │ Sun, 04 Oct      ────────●         Wed, 07 Oct    │  │
│ │ Room · Guests                                     │  │
│ └───────────────────────────────────────────────────┘  │
│ ┌ 1 Personal ───────┐ ┌ 5 Terms ─ 3 of 8 [Agree all] ┐  │
│ ┌ 2 Contact ────────┐ │ text, then its own boxes     │  │
│ ┌ 3 Identity ───────┐ │ ☐ item (in place)            │  │
│ ┌ 4 Preferences ────┐ ┌ 6 Sign ─────── [Clear] [⤢] ──┐  │
│                       │ inline pad                   │  │
│ 5 boxes left · signature needed        [ Sign card ]   │
│                   Staff: back to desk                  │
└────────────────────────────────────────────────────────┘
```

- The page is a set of section cards: Personal, Contact, Identity, Preferences, Terms and Sign. Personal to Preferences are the left column on wide screens, Terms and Sign the right. On a phone they stack in one scroll.
- The stay card at the top has the check-in and check-out dates large, with the weekday, and the night count between them. The booking number is small at the top right. The other visible stay fields sit in an even grid (4 columns, 2 on a phone). A field the Layout tab hides does not show. Prices, when visible, sit in their own row under a divider.
- The sticky footer says what is missing ("5 boxes left", "signature needed") beside Sign card.
- The fields follow the Layout tab. The ID document picked by staff shows masked and read-only. If none is picked and the field is Required, the guest types it.
- The language Select lists the languages from `RegistrationTermsResolver`. Switching resets the ticks and keeps the typed values.
- The signature pad is on the page, not in a dialog. Clear sits above the pad, away from Sign card. ⤢ opens the pad full screen with the strokes so far. Done copies the strokes back to the page. Cancel (or Escape) drops what was drawn full screen and keeps the page as it was.
- Sign card stays disabled until every Required field, every checklist box and the signature are done.
- "Guest X of N": N is the guests of the unit that need a card (`RegistrationCardRequirement::requiredFor`). It shows only when the open guest is one of them.
- The only way out is the small "Staff: back to desk" link to `registration.show`. Staff are present, so it has no PIN.

## Thank-you screen

`registration.done`, in the guest layout. It shows no details of the guest who signed.

- The next guest is the first of `RegistrationCardRequirement::missingFor` on the same unit: primary guest first, then by id. It is derived, not stored, so a card signed elsewhere drops out. "Thank you. Next: Ali Hassan (2 of 3)" with Continue to that guest's screen.
- When no guest is left: "All done. Please return the device." and "Staff: back to desk".

## Terms checklist

- The card page shows the terms text with each checklist item as a checkbox in the section where the author wrote it (see [Terms documents and presets](02-terms-documents-and-presets.md)). Desk and remote signing use the same component.
- A counter beside Agree to all shows how many items are ticked, for example "3 of 8".
- Agree to all ticks every box in the text in one tap. The boxes stay visible and each one can be unticked. When every box is ticked, the button reads Untick all.
- Sign stays disabled until every item is ticked.
- `SignRegistrationCard` resolves the terms version again and refuses unless the submitted ticks cover every item. The browser does not decide this.
- `snapshot` keeps each item's rendered text, its position and the tick time.
- The PDF prints the items as ticked boxes in the signed locale.

## Snapshot

`snapshot` is jsonb, allowed because it is an issued document. It holds the values of the visible fields, the stay details, the filled terms text, the ticked items, the locale, the signer name, and the ID type with a masked number. Prices appear only when their fields are visible. A signed card never reads the live ID document.

## Labels in Bahasa Malaysia

The card's labels are in `lang/ms.json` (new folder `lang/` at the project root, approved). The keys are the English source strings. The Bahasa Malaysia text is a first draft and needs a native reader. The page gets the labels for the open language as a prop.

## Rules

- Signed cards are never edited. Changes void the card and a new card is signed.
- The PDF is rendered with dompdf in the signed locale, from the snapshot only. `StoreRegistrationCardPdf` keeps it as the `pdf` media on the first download. A signed card never changes, so the stored file is reused.
- The PDF layout follows the preview on the Layout tab: property name and "Registration card", sections (Guest details, Stay details, Estimated charges at booking) with a small heading and two columns of label over value, the offers line as a checkbox, Terms with the checklist as boxes, then the signature line with "Guest signature" below it.
- Fonts are in `resources/fonts`. The body uses DejaVu Sans, and switches to Noto Sans SC when the text has Chinese, Japanese or Korean characters. The checkboxes use Noto Sans Symbols 2. dompdf runs out of memory at 128 MB on the full 10 MB Noto Sans SC, so the bundle is a static Regular subset (2 MB): the 6,763 GB2312 hanzi, Latin and common punctuation. Traditional-only and rare characters print as blanks. Bold is not available in that font. dompdf keeps its font cache in `storage/fonts` (git-ignored).
- The signature block never splits across pages and the footer sits in the page margin, so long terms never overlap it.
- Print blank form renders the draft as a PDF without a signature, for a guest who signs on paper. It lists every visible field (empty when unanswered), unticked boxes and a signature line, in the open language.
- `snapshot` jsonb is allowed: it is an issued document.
- Remote ID upload accepts JPEG, PNG or WebP up to 10 MB, on a throttled POST.

## Permissions

`registration-card.sign` (Owner, Manager, Front desk), `registration-card.send-link` (Owner, Manager, Front desk), `registration-card.void` (Owner, Manager).

## Signing tablet (parked)

wastays pairs a counter tablet (QR or 6-character code, 10 minutes, single use), parks it on an idle screen and lets staff press Send to tablet. The tablet never signs in, has a Back button for a wrong reservation, and is switched on per property. If we build it, the idle screen polls every few seconds instead of using Reverb, and it opens the same guest screen. Parked: the guest's phone and this device cover small properties.

## Lessons from wastays

- The signature was a base64 `signature_data_url` text column. `pre_checkins` needed a token table. Signed URLs replace it.
- A staff page that also held the signature pad showed staff actions to the guest. Staff review and guest signing are separate pages, and every hand-over path opens one guest screen.
- Clear next to Sign lost signatures. A cramped pad on a phone needed a full-screen mode.
- After signing, the screen must not keep the last guest's ID number on a counter.
- The "show pricing" setting is already covered here by the Prices visibility on the Layout tab.

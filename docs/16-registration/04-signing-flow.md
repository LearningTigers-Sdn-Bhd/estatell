# Registration card signing

## Purpose

Capture guest details, ID and signature at check-in, at the desk or remotely.

## Build order

| Step | Commit                                                | Delivers                                                                                 |
| ---- | ----------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| 1    | `feat(registration): add the desk signing flow`       | Card table, Actions, staff card page, signature pad, checklist ticks, void, Malay labels |
| 2    | `feat(registration): render the signed card as a PDF` | PDF, signature and PDF media, Download button                                            |
| 3    | `feat(registration): sign the card remotely`          | Signed link, guest layout, guest page, ID upload, language selector                      |

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
2. Sign:
    - At the desk: `signature_pad` in a large dialog, then `SignRegistrationCard`.
    - Remote (step 3): `SendRegistrationCardLink` emails a `URL::temporarySignedRoute` link that lasts 7 days, with a Resend button. The guest completes fields, uploads ID and signs.
3. `CheckInUnit` (module 18) checks cards against `registration_card_scope` (PrimaryOnly, or EveryAdult, which skips a guest known to be under 18 on arrival) when `card_required_for_check_in` is on. Users with `front-desk.check-in-without-card` can override. The override is logged. `RegistrationCardRequirement` (a Service, step 1) tells it which guests still need a card.

## Actions

| Action                    | Does                                                                                                                                                                                    |
| ------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `PrepareRegistrationCard` | Finds or creates the draft for a unit guest.                                                                                                                                            |
| `SignRegistrationCard`    | Checks the checklist and the signature, updates the guest with `UpdateGuest`, sets `guests.preferred_locale`, stores the signature, freezes the snapshot and sets the status to Signed. |
| `VoidRegistrationCard`    | Voids a Draft or Signed card with a reason and logs it.                                                                                                                                 |

`SignRegistrationCardRequest` checks the Required fields by the Layout tab, so errors show on the field. Expected refusals (already signed, closed unit, missing tick, bad signature, language with no terms) return false, not an HTTP error. The controller shows a toast.

## Staff card page

- `GET /p/{code}/registration/{unitGuest}` (`registration.show`), `POST …/sign` (`registration.sign`) and `POST …/void` (`registration.void`). Routes are in `routes/registration.php`, the page is `pages/registration/card.tsx`. The guest row on the reservation page links to it.
- Form archetype, full page. The fields follow the Layout tab. The terms are in a scroll box, and the checklist boxes are under it.
- The language Select lists the languages from `RegistrationTermsResolver`. Switching resets the ticks and keeps the typed values.
- Signing opens a large dialog with a signature pad. The Sign button stays disabled until every Required field, every checklist box and the signature are done.
- A signed card is read-only and shows Void. A unit that is cancelled, a no-show or checked out has no card, and neither has a guest who was merged or anonymized.

## Terms checklist

- The card page shows the terms text, then one checkbox per checklist item (see [Terms documents and presets](02-terms-documents-and-presets.md)). Desk and remote signing use the same component.
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
- The PDF is rendered with dompdf in the signed locale.
- `snapshot` jsonb is allowed: it is an issued document.
- Remote ID upload accepts JPEG, PNG or WebP up to 10 MB, on a throttled POST.

## Permissions

`registration-card.sign` (Owner, Manager, Front desk), `registration-card.send-link` (Owner, Manager, Front desk), `registration-card.void` (Owner, Manager).

## Lessons from wastays

The signature was a base64 `signature_data_url` text column. `pre_checkins` needed a token table. Signed URLs replace it.

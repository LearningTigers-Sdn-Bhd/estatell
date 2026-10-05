# Registration card signing

## Purpose

Capture guest details, ID and signature at check-in, at the desk or remotely.

## Data model

```
registration_cards    id, unit_guest_id, number, status (RegistrationCardStatus),
                      identity_document_id (nullable), terms_id, signed_at, signer_name,
                      snapshot (jsonb, frozen at signing), timestamps
```

- Signature PNG and rendered PDF are media files.

## Enums

`RegistrationCardStatus`: Draft, Signed, Void.

## Flow

1. `PrepareRegistrationCard` pre-fills guest, ID document and stay details.
2. Sign:
    - At the desk: full-screen `signature_pad`, then `SignRegistrationCard`.
    - Remote: `SendRegistrationCardLink` emails a `URL::temporarySignedRoute` link. The guest completes fields, uploads ID and signs.
3. `CheckInUnit` checks cards against `registration_card_scope` (PrimaryOnly or EveryAdult) when `card_required_for_check_in` is on. Users with `front-desk.check-in-without-card` can override. The override is logged.

## Terms checklist

- The card page shows the terms text, then one checkbox per checklist item (see [Terms documents and presets](02-terms-documents-and-presets.md)). Desk and remote signing use the same component.
- Sign stays disabled until every item is ticked.
- `SignRegistrationCard` parses the resolved terms version again and refuses unless the submitted ticks cover every item. The browser does not decide this.
- `snapshot` keeps each item's rendered text, its position and the tick time.
- The PDF prints the items as ticked boxes in the signed locale.

## Rules

- Signed cards are never edited. Changes void the card and a new card is signed.
- The PDF is rendered with dompdf in the signed locale.
- `snapshot` jsonb is allowed: it is an issued document.

## Permissions

`registration-card.sign`, `registration-card.void`, `registration-card.send-link`.

## Lessons from wastays

The signature was a base64 `signature_data_url` text column. `pre_checkins` needed a token table. Signed URLs replace it.

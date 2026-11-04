# Kiosk signing, check-in, keys, check-out and walk-in

## Purpose

The second half of the kiosk contract (`docs/KIOSK_API.md` in the estatell-kiosk repo), after [find, ID and guest](02-kiosk-check-in-endpoints.md). Same rules: `kiosk:*` token, the token's property only, the [error shape](01-api-tokens-and-kiosk-pairing.md#errors), `Idempotency-Key` on every write. This file does not change the contract.

## Registration card

| Call                                                   | Sends                              | Returns                         |
| ------------------------------------------------------ | ---------------------------------- | ------------------------------- |
| `GET /api/kiosk/v1/stays/{id}/registration-card`       | none                               | `{ terms_version, terms_text }` |
| `POST /api/kiosk/v1/stays/{id}/registration-card/sign` | `{ terms_version, signature_png }` | `{ signed }`                    |

- The stay is found with `KioskStays::find`: Confirmed, inside the [check-in window](02-kiosk-check-in-endpoints.md#calls). Another property's stay, a missing one and one outside the window all answer `booking_not_found` (404).
- **Terms** come from `RegistrationTermsResolver`, the resolver the staff and guest card screens use (the room type's document, else the property default). The text is the Markdown body with the property placeholders filled (`TermsRenderer`), checklist lines (`- [ ] ...`) included. `Accept-Language` (`en`, `ms`, `zh`) picks the language (`KioskLanguage`). Estatell has no Chinese terms (`Locale` is `en` and `ms`), so `zh` falls back to the guest's own or the property's language. A property with no published terms answers `server_error` (409).
- **Sign** is the existing `SignRegistrationCard` action, called with no actor (the activity log has no causer). It signs the **primary guest's** card. The contract sends no checklist ticks, so the guest ticking on the kiosk screen means **every** checklist item counts as ticked. The signer name is the guest's name and the guest's details stay as the kiosk saved them. The card links the guest's newest ID document.
- `terms_version` must be the version the guest saw (`registration_card_terms.version`, in the language the same `Accept-Language` resolves). Another version, a bad body, a signature that is not a PNG or is over 1 MB, a stay without a primary guest and a closed guest all answer `200 { signed: false }` and write nothing. The contract has no validation error code, as for `identity`.
- `signature_png` is a PNG as plain base64 or a `data:image/png;base64,` URL. `SignRegistrationCard` checks the prefix, the PNG magic bytes and the size, then stores it as the card's signature media like the staff flow.
- A card that is already signed answers `signed: true` again and writes nothing, so a retry without a key is safe.
- The signature, terms and name never appear in a log or an exception message.

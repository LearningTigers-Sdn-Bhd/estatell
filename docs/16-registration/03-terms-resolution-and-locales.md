# Terms resolution and locales

## Purpose

Show the right terms, in the guest's language, on every card.

## Service

`RegistrationTermsResolver` returns the terms version row and the available locales.

1. **Document**: the unit's room type `terms_document_id`, else the property default.
2. **Locales offered**: locales with a published version in that document.
3. **Starting locale**: `guests.preferred_locale` if available, else `property_settings.default_locale`.
4. **Version**: the latest published version for that document and locale.

Used by the card page, the remote signing link and the PDF.

## Locale selector

- Switching locale re-renders the terms and the field labels (`lang/{locale}.json`, passed as a prop to this page).
- Guest input is kept when switching. Checklist ticks reset, because the wording changes.
- On signing, `guests.preferred_locale` is updated.

## Edge cases

| Case                                        | Behaviour                                                   |
| ------------------------------------------- | ----------------------------------------------------------- |
| Room type document lacks the guest's locale | Fall back to the default locale within that document        |
| Room type has no document                   | Use the property default document                           |
| Room type changes after signing             | Card stays valid. Optional "Terms changed, re-sign?" prompt |
| New version published while card is a draft | Draft uses the new version (resolved at signing)            |
| New version published after signing         | No change                                                   |

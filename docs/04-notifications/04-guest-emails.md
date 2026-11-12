# Guest emails

## Purpose

Let a property write the emails it sends to guests in its own words, without losing the parts the guest needs.

## Settings › Emails

- Route `settings.emails.edit` (`/p/{code}/settings/emails`), `settings.manage`. `PUT` and `DELETE /settings/emails/{email}` save and reset one email.
- The page lists only the emails the property sends, given its features (`GuestEmailText::available()`). With none, the page answers 404 and the sidebar item (System › Emails) is hidden.
- Each email is one `SettingsSection`: **Subject**, **Message** (an empty line starts a new paragraph), the placeholder list with an example of each, and a **Preview** filled with example details.
- **Reset to default** (with a confirm) shows only when the property has its own text.
- A placeholder the email does not know (`{guestname}`) is refused: "Unknown placeholder: {guestname}. Use only the placeholders listed below."
- Saving logs `guest_email.updated` (old and new text); resetting logs `guest_email.reset`.

## Emails

`GuestEmail` holds each email's label, description, default subject and message, and placeholders.

| Email                   | Sent when                                                    | Placeholders                                                                   |
| ----------------------- | ------------------------------------------------------------ | ------------------------------------------------------------------------------ |
| E-invoice for the guest | Staff press **Email to guest** on an e-invoice LHDN accepted | `{guest_name}`, `{hotel_name}`, `{invoice_number}`, `{amount}`, `{stay_dates}` |

The property can change the subject and the message only. The details table and the **View e-invoice** button (the MyInvois link) are always added under the message, so the guest can always open their copy.

## Storage

Table `property_guest_emails`: `property_id`, `email` (`GuestEmail`, CHECK constraint), `subject` (200), `body` (text), unique per property and email. No row means the default text.

## Sending

A guest is not a user, so a guest email skips the preference matrix and goes by mail to the address staff enter (`Notification::route('mail', …)`). Replies go to the property's contact email. The send is wrapped in `Context::scope` with the property, so the [mail log](03-mail-log.md) row has it.

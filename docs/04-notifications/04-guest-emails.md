# Guest emails

## Purpose

Let a property write the emails it sends to guests in its own words, without losing the parts the guest needs.

## Settings › Emails

Settings › Emails is the one place for every email the property sends to guests. Each module adds its emails here, so staff always look in one place (System › Emails in the sidebar).

- Routes (all `settings.manage`):

    | Route                     | URL                                              | Does                                                |
    | ------------------------- | ------------------------------------------------ | --------------------------------------------------- |
    | `settings.emails.index`   | `GET /p/{code}/settings/emails`                  | The list                                            |
    | `settings.emails.edit`    | `GET /p/{code}/settings/emails/{email}`          | One email's editor                                  |
    | `settings.emails.preview` | `POST /p/{code}/settings/emails/{email}/preview` | The rendered email for the text being edited (JSON) |
    | `settings.emails.update`  | `PUT /p/{code}/settings/emails/{email}`          | Save                                                |
    | `settings.emails.reset`   | `DELETE /p/{code}/settings/emails/{email}`       | Back to the default text                            |

- Only the emails the property sends, given its features, are listed and can be opened (`GuestEmailText::available()`). With none, the pages answer 404 and the sidebar item is hidden.
- **The list** is a table grouped by module (`GuestEmail::module()`): the email, when it is sent (`sentWhen()`) and a **Default** or **Edited** badge. A row opens the editor.
- **The editor** is a fixed-height `Workspace`. The main panel has **Subject**, **Message** (an empty line starts a new paragraph) and the placeholder list; pressing a placeholder adds it where the cursor was. **Save email** is in the floating Save bar, shown while the text has changed, with the unsaved-changes guard. **Reset to default** (with a confirm) is under More actions, only while the property has its own text.
- **The preview** is in the right rail (`asideWidth="email"`: 28rem, 38rem from `xl`, so the 570px email fits). It is the real email: `GuestEmailPreview` builds the email's notification with example details and the property's real name, and renders it with the same mail template it is sent with. Above it are the subject and where replies go. It refreshes 400 ms after typing stops; an older answer never replaces a newer one. Unknown placeholders show as empty in the preview; saving refuses them.
- The e-invoice **Email to guest** dialog shows the same rendered email, with the real details (`SubmissionView` adds `guest_email.html`). The E-invoicing General tab links to the e-invoice email.
- A placeholder the email does not know (`{guestname}`) is refused: "Unknown placeholder: {guestname}. Use only the placeholders listed below."
- Saving logs `guest_email.updated` (old and new text); resetting logs `guest_email.reset`.

## Emails

`GuestEmail` holds each email's label, module, when it is sent, description, default subject and message, and placeholders.

| Email                   | Sent when                                                    | Placeholders                                                                   |
| ----------------------- | ------------------------------------------------------------ | ------------------------------------------------------------------------------ |
| E-invoice for the guest | Staff press **Email to guest** on an e-invoice LHDN accepted | `{guest_name}`, `{hotel_name}`, `{invoice_number}`, `{amount}`, `{stay_dates}` |

The property can change the subject and the message only. The details table and the **View e-invoice** button (the MyInvois link) are always added under the message, so the guest can always open their copy.

## Storage

Table `property_guest_emails`: `property_id`, `email` (`GuestEmail`, CHECK constraint), `subject` (200), `body` (text), unique per property and email. No row means the default text.

## Sending

A guest is not a user, so a guest email skips the preference matrix and goes by mail to the address staff enter (`Notification::route('mail', …)`). Replies go to the property's contact email. The send is wrapped in `Context::scope` with the property, so the [mail log](03-mail-log.md) row has it.

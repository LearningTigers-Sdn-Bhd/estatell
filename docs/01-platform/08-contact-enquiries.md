# Contact Us enquiries

## Purpose

A hotel that is not a customer yet can write to Estatell from the public site. The enquiry is saved, the sales inbox gets an email, and platform admins work the list in the admin area.

## Public page

`/contact` (`contact`), no sign-in. A hotel fills in:

| Field              | Column       | Rule                                                                |
| ------------------ | ------------ | ------------------------------------------------------------------- |
| Hotel name         | `hotel_name` | Required, up to 150 characters.                                     |
| Location / address | `location`   | Required, up to 255 characters.                                     |
| Your name          | `name`       | Required, up to 120 characters.                                     |
| Email address      | `email`      | Required, a valid email address.                                    |
| Mobile number      | `phone`      | Required, digits with an optional `+`, spaces, dashes and brackets. |
| Number of rooms    | `room_count` | Required, a whole number from 1 to 9999.                            |
| Your enquiry       | `message`    | Required, up to 2000 characters.                                    |

- `POST /contact` (`contact.store`) is throttled to 5 a minute per IP.
- A hidden `website` field is a honeypot. A request that fills it is refused with a validation error and saves nothing.
- After a successful send the page redirects back and shows a thank-you message once (session key `contact_sent`).
- The navbar, the closing "Contact Us" link beside "Chat on WhatsApp", and both footers link to the page. It is in `sitemap.xml` and allowed in `robots.txt`.

## How an enquiry is saved

`SubmitContactEnquiry` creates a `contact_enquiries` row with status `new`, then sends the `ContactEnquiryReceived` notification (queued, mail) to `config('estatell.contact_notify_email')`. Set `CONTACT_NOTIFY_EMAIL` in the environment. The default is `hello@estatell.com`. The row is saved first, so a failed email loses nothing. The email sets Reply-To to the hotel's address and links to the enquiry in the admin.

The table has no `property_id`. An enquiry comes from a hotel that has no property in Estatell yet.

## Status

`ContactEnquiryStatus`: `new`, `contacted`, `closed`. Any status can move to either of the others, so a closed enquiry can be reopened. `UpdateContactEnquiryStatus` locks the row, refuses a move to the same status (returns false), and writes an activity log entry `contact-enquiry.status-changed` with `from` and `to`.

## Admin page

`/admin/enquiries` (`admin.enquiries.index`), platform admins only (`can:admin`). The sidebar item is "Enquiries" and shows the number of `new` enquiries as a badge.

- Header counts, a status filter, search (part of hotel name, name, email or location), sorting by received time and pagination, all in the URL.
- Columns: received (UTC), hotel and location, contact name and email, rooms, status, actions.
- Click a row to open its detail page. The Actions menu also has a keyboard-accessible View enquiry link and can mark the enquiry New, Contacted or Closed without leaving the list. It omits the current status and keeps the filters and pagination.
- `/admin/enquiries/{id}` (`admin.enquiries.show`) shows the message in its own panel, property and contact cards, and status actions.
- Delete is available from the list Actions menu and the detail page. Both ask for confirmation and name the hotel. `DELETE /admin/enquiries/{id}` (`admin.enquiries.destroy`) requires `can:admin` and `support.2fa`, permanently deletes the enquiry, and returns to the list. A `contact-enquiry.deleted` audit entry records the admin and enquiry ID without copying the message or contact details.
- `PUT /admin/enquiries/{id}/status` carries `support.2fa`, like every admin write route.

## Privacy (PDPA)

An enquiry holds a name, an email address and a mobile number, kept in plain columns and visible only to platform admins. There is no automatic deletion yet. Decide a retention period and add a prune job like `PruneSignInEvents` once the privacy policy states one.

# Concierge page

## Purpose

A page on the guest's own phone that checks the guest in with no front desk and no kiosk hardware, and then carries the stay. Check-in is the product the landing page calls Self Check-in: scan the lobby QR, give an ID, sign, pay online, receive a smart-lock PIN. After check-in the same link opens the stay pages: ask the front desk for something, see the facilities and nearby places, see and pay the bill, and check out.

The kiosk is a separate product that needs hardware. The page shares the check-in rules with the kiosk and nothing else: not its token, not its actor, not its routes.

**Status: built.** The lobby hub, the property guide, contact, nearby places, self check-in, the in-stay pages, the bill with check-out, documents, refund requests and the e-invoice request are built. See [Not built](#not-built) and [Compared with wastays](#compared-with-wastays).

## Guest design

Guest concierge pages retain the locked editorial identity: warm paper, olive primary actions and fine borders. The landing page keeps its editorial typography. Inner pages use standard Estatell controls, rounded buttons, cards, badges and content typography. The landing page keeps its full-screen composition, angled coastal artwork, desktop separators and mobile check-in action below the image. Other pages share `GuestFrame`, with property identity, a back link, the standard Estatell `PageHeader` and responsive content. The property guide uses `TabLinks` for its route-based chapters. Arrival times and location appear first; travel and parking facts, policies, facility details, FAQs and Wi-Fi are grouped into readable sections. The plain guest navbar stays hidden on concierge routes.

The theme is scoped to concierge pages, including portalled form controls. Staff screens and the separate registration-card pages keep their existing design. Forms, validation, payments, signatures and room-key visibility rules do not change.

Guest service pages group request forms with their history, contact methods with front desk hours, and bill entries with check-out actions. The guide keeps route-based chapters, readable arrival times, facility details and Wi-Fi instructions. Empty sections explain how to get help without inventing property information. Guest booking summaries, receipts and invoices use olive headings and shaded information strips and totals; they retain the property's print fonts and document content. Staff PDFs keep their configured appearance.

Inner concierge pages share a front desk help footer at the end of the page. The contact page omits it. Payment and key-access problems keep their contextual contact actions.

## Decisions

| Area            | Decision                                                                                                                                                                                                                           |
| --------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Where it lives  | Inside estatell, in `routes/concierge.php`, outside `kiosk/v1`. No separate site and no new API                                                                                                                                    |
| Feature flag    | `Concierge`, per property, off by default, set by admins. Every Concierge route answers 404 while it is off or the property is suspended                                                                                           |
| Lobby QR        | One per property. It opens `/stay/{code}`, a hub of services (check in, book a room, property guide, nearby places, contact) that is public and carries no booking. Staff print it from Settings, Concierge                        |
| Stay link life  | The stay's link (`/c/{token}`) works until 7 days after the departure date, so a guest can still open their documents, ask for a refund and request an e-invoice                                                                   |
| Who can use it  | Guests with a booking, and walk-ins when the property turns the walk-in switch on                                                                                                                                                  |
| Proof of a stay | The booking code (`reservations.short_code`, the 6-character code the guest is given, not the staff `reference`) alone, or the stay's own link `/c/{token}`. The link is the stay's pre-check-in token. The lockout slows guessing |
| Payment         | Online through Razorpay Curlec. It pays what the kiosk's paid-enough rule wants: the Collect now taxes and the security deposit                                                                                                    |
| Key             | A smart-lock PIN shown on the page after check-in. No key card                                                                                                                                                                     |
| Actor           | The page is its own channel. Its log events are `concierge.*`, never `kiosk.*`                                                                                                                                                     |
| Multi-room      | A booking code that matches more than one room opens nothing. Each room uses its own link                                                                                                                                          |
| Other guests    | The page signs the primary guest's card only. A card still missing for another guest sends the guest to the desk                                                                                                                   |

## Flow

```
 Lobby QR  ──►  /stay/{code}  ──►  booking code ───────────────────────┐
                    │                                                    ▼
                    └──► no booking? ──► /stay/{code}/walk-in ──► room ──► details ──► /c/{token}
                                                                           │
   ID  ──►  registration card (if required)  ──►  payment (if due)  ──►  check in  ──►  room + PIN
```

`/c/{token}` is one page. `ConciergeProgress` reads the stay and picks the step, so a guest can leave and come back to the same link and land on the right step.

| Step           | Shown when                                                                                                                         |
| -------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| `identity`     | (open from the link, even before the arrival day) The guest has no ID document, or an enabled foreign-only tax needs a nationality |
| `card`         | The property requires the card for check-in and the primary guest has not signed                                                   |
| `payment`      | `SelfCheckInPayment::shortfall` is more than zero                                                                                  |
| `check_in`     | Everything above is done                                                                                                           |
| `key`          | The page checked the stay in during the last 30 minutes. It shows the room and the PIN                                             |
| `done`         | The stay is checked in by another channel, or the 30 minutes ended. No room and no PIN                                             |
| `not_open`     | Details, card and payment are done, but it is before the arrival day or the check-in time                                          |
| `hold_expired` | A walk-in's unpaid hold was released                                                                                               |
| `desk`         | Anything the guest cannot fix: a closed stay, no guest, a card missing for another guest, no terms                                 |

## Routes

| Route                                  | Name                            | Purpose                                                 |
| -------------------------------------- | ------------------------------- | ------------------------------------------------------- |
| `GET/POST /stay/{code}`                | `concierge.lobby`, `.find`      | Lobby, and open a stay by booking code                  |
| `GET /stay/{code}/walk-in`             | `concierge.walk-in`             | Offers for tonight; nothing is booked                   |
| `GET /stay/{code}/walk-in/details`     | `concierge.walk-in.details`     | The details form for the room picked; nothing is booked |
| `POST /stay/{code}/walk-in`            | `concierge.walk-in.book`        | Book the room and save the details, together            |
| `POST /stay/{code}/walk-in/returning`  | `concierge.walk-in.returning`   | Recognise a returning walk-in (email or phone) and book |
| `GET /c/{token}`                       | `concierge.show`                | The stay page                                           |
| `POST /c/{token}/identity`             | `concierge.identity`            | Save the typed ID and optional photos                   |
| `POST /c/{token}/sign`                 | `concierge.sign`                | Sign the primary guest's registration card              |
| `POST /c/{token}/payment`              | `concierge.payment`             | Start the Curlec order. JSON for the checkout           |
| `POST /c/{token}/payment/confirm`      | `concierge.payment.confirm`     | Confirm a finished checkout by its signature            |
| `POST /c/{token}/check-in`             | `concierge.check-in`            | Check in with the front desk's rules                    |
| `GET/POST /c/{token}/e-invoice`        | `concierge.e-invoice`, `.store` | See and request the stay's e-invoice                    |
| `GET/PUT /p/{code}/settings/concierge` | `settings.concierge.*`          | Staff: lobby QR and the walk-in switch                  |

### Staff settings page

Settings › Concierge follows the Settings archetype, like Booking website. Each tab is a `TabLinks` link with its own GET route, controller method and page, and the server sends only that tab's data. The open tab is the last breadcrumb. Every tab shares the page header (View lobby, Print lobby QR) and the printed QR sheet, through `ConciergeShell`.

| Tab              | Route                                 | Page                                  | Right rail                                            |
| ---------------- | ------------------------------------- | ------------------------------------- | ----------------------------------------------------- |
| Overview         | `settings.concierge.index`            | `settings/concierge/index`            | Guide progress, lobby QR, lobby photo                 |
| Guest guidelines | `settings.concierge.guest-guidelines` | `settings/concierge/guest-guidelines` | None (arrival and departure, then interesting topics) |
| Getting here     | `settings.concierge.getting-here`     | `settings/concierge/getting-here`     | Property profile (the address)                        |
| Policies         | `settings.concierge.policies`         | `settings/concierge/policies`         | Charge policies from Room revenue                     |
| FAQs             | `settings.concierge.faqs`             | `settings/concierge/faqs`             | None (a table of questions grouped by topic)          |
| Amenities        | `settings.concierge.amenities`        | `settings/concierge/amenities`        | None                                                  |
| Nearby places    | `settings.concierge.nearby-places`    | `settings/concierge/nearby-places`    | None                                                  |
| Wi-Fi            | `settings.concierge.wifi`             | `settings/concierge/wifi`             | None (a table of networks)                            |
| Contact          | `settings.concierge.contacts`         | `settings/concierge/contacts`         | Phone and email from the property profile             |

The tab content sits in a fixed-height `Workspace` with a 28rem rail, like the reservation page: each panel scrolls on its own, and a tab with no rail takes the full width. Sections inside a panel use `PanelSection`, not cards. The Overview rows link to each tab. Guest guidelines is ready when the arrival instructions are written; Getting here is ready when the property address is set. Guest guidelines (`SaveGuestGuidelines`, `property_settings`) and Getting here (`SaveGettingHere`, `property_transport_details`) each save on their own and log `concierge.guest-guidelines-updated` and `concierge.getting-here-updated`. Amenities and Nearby places edit the same data as the Booking website tabs of the same name.

**Interesting topics** (on Guest guidelines) are a table: the Hide/Show switch on the left, then the Topic and Description columns, then a row menu with Edit and Delete. Add topic and Edit open a dialog; Delete asks first. A topic that is off stays in the list but guests do not see it, and it still counts toward the 20 topics. Actions: `AddInfoTopic`, `UpdateInfoTopic`, `SetInfoTopicActive`, `RemoveInfoTopic`; they log `concierge.topic-added`, `-updated`, `-shown`, `-hidden` and `-removed`, and only a real change is logged.

All guest routes sit behind the `concierge` middleware (`EnsureConciergeIsActive`) and are throttled. A write through a link works only on a Confirmed or CheckedIn stay; a link to a cancelled stay can only be read, to show `hold_expired`.

## Payment

A Curlec order is for a booking hold or for a stay. `gateway_payments.reservation_unit_id` is set for a stay order, and exactly one of it and `booking_hold_id` is set (a check constraint). Refunds, the webhook and the alerts treat both kinds the same way.

Stay payments are Live by default. A property can switch on `property_settings.concierge_test_payments_enabled` (Settings › Concierge › Guest actions › Test payments, off by default). New orders then use Curlec's Test keys and are saved with mode Test, so no real money moves; each payment keeps its mode, so its refund and webhook use the same keys. It is separate from the booking website's payment mode.

1. `StartStayPayment` creates the order for the shortfall. A guest who retries with the same amount due gets the same unpaid order.
2. The page opens Curlec's checkout. When it finishes, the page posts the order id, payment id and signature to `payment/confirm`.
3. `ConfirmStayPayment` checks the signature and that the order is this stay's, then `RecordStayPayment` posts an Advance with the Online method on the unit folio. The folio line key is `online:{order}:{unit}`.
4. Curlec's `order.paid` webhook runs the same `RecordStayPayment`, so a guest who closes the tab is still credited. Calling it twice posts once.
5. Money that was taken but could not be posted alerts the admins (`AlertOnlinePaymentProblem`) and the webhook event is retried.

Refunding a stay payment uses the existing refund code, which finds the order by the payment's reference.

To verify: the checkout script address in `payment-step.tsx` is Razorpay's, which Curlec documents. Check it against Curlec's current docs before the first live payment.

## The stay screen

For a guest who is checked in (the `home` step, and under the door code on the `key` step) the page has, from the top:

1. **Stay details:** the booking code, the dates and the number of nights. The room is never shown here, because a stay opens from its booking code alone.
2. **Stay actions:** Housekeeping and Report an issue (each opens the requests page with that type picked), and Check out (the bill page). After the guest asks the desk to check them out, the Check out tile becomes "Check-out asked".
3. **Property services:** Wi-Fi, Contact us, Nearby places, the Property guide and Hotel facilities.
4. **More actions** (collapsed): My requests, My bill, and the stay's documents.

## In-stay pages

They open from the stay menu once the guest is checked in. Each works only for a stay that is CheckedIn: any other stay is sent back to the stay page.

| Page       | What it does                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| ---------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Requests   | The guest picks a type (housekeeping, maintenance, complaint or other) and writes what they need. It becomes a [guest request](../25-guest-requests/01-guest-requests.md) with the source Concierge and no staff opener, in the room the stay sleeps in. The page lists the guest's own requests with their status; a resolved one shows its note, a cancelled one does not. A stay may have 5 open requests at once (`SubmitConciergeRequest::MAX_OPEN`)                         |
| Facilities | The amenities the property ticked, by category, from the same registry the booking engine shows. Staff switch amenities on in Settings, Concierge, Amenities. That is the same list as Settings, Booking website, Amenities, so it works with or without the booking engine. A facility the owner gave details for shows where it is, its opening hours, its cost, whether guests must reserve and how, and a note (`property_amenities`). A facility with no details stays a tag |
| Nearby     | The places the property listed and left active, under the property's own name and note, each with its straight-line distance from the property (when the property has coordinates) and a link that opens it in maps Staff add and manage them in Settings, Concierge, Nearby places. That is the same list as Settings, Booking website, Nearby places, so it works with or without the booking engine                                                                            |
| Bill       | Every line of the stay's own folios, what is still owed, and any credit the property holds. See [Bill and check-out](#bill-and-check-out)                                                                                                                                                                                                                                                                                                                                         |

## Bill and check-out

- The bill is the sum of the stay's unit folios. A line is a charge or tax (positive) or a payment (negative). It labels the balance as posted charges and explains that the booking summary also includes booked charges not yet posted.
- **Owing money:** the guest pays the whole open bill online. It is the same Curlec order and webhook as the check-in payment, and it posts a Settlement (before check-in it posts an Advance).
- **A credit or held deposit:** the page shows credit on posted charges. It may cover upcoming charges or a deposit; the front desk confirms what is refundable at check-out. A stay with a deposit settles to zero only after the desk returns it, so the guest checks out at the desk.
- **Check-out:** offered when the bill is zero and the check-out window is open, the same window the kiosk uses (the departure day). `CheckOutConciergeStay` calls the front desk's `CheckOutUnit` with no staff actor, logs `concierge.checked_out` with the property id only, and answers the same result again on a retry. An earlier check-out is the desk's.
- **Asking the desk:** when the guest cannot check out alone (money is owed, a deposit is held, or it is before the departure day), the bill page offers **Ask the front desk to check you out** with an optional note. It becomes a guest request of the type Other whose details start with `Check-out request` (`RequestConciergeCheckOut`), in the staff Requests list. One can be open at a time. While it is open the bill and the stay page show "Check-out asked".
- After check-out the link shows the thank-you, the documents and, when a refund request was sent, how it is going, and the e-invoice page. The refund request and the e-invoice request are the only writes it accepts; every other write answers 404.

## Welcome email and webhook

When a stay checks in, by the page, the front desk or the kiosk, a listener of `StayCheckedIn` (`QueueStayWelcome`) asks for the welcome and schedules the **deadline** of that check-in, 20 minutes later (`SendStayWelcomeJob::DEADLINE_SECONDS`). The guest gets one welcome, and it has the PIN in it whenever the PIN can be made. `WelcomeStay` decides when it goes:

- **No PIN to wait for** (the stay has no lock, or TTLock is off for the property): the welcome goes at once, with no PIN line.
- **A PIN is on its way** (`KioskPin::canRetry`): the welcome waits. It goes **as soon as the PIN is ready**, with the PIN, the room and the time the PIN ends. Every end of a PIN sync (`SyncStayPasscodesJob`) asks again, so there is no polling.
- **The PIN could not be made**: the PIN sync tries again by itself when asking again could help (TTLock did not answer; see [TTLock integration](../27-door-locks/01-ttlock-integration.md)), so the welcome keeps waiting. At the **deadline** the welcome goes anyway, **without the PIN**, and says the PIN will show on the stay page. `door-locks.welcome-without-pin` is written to the log. The guest always gets their link.

The welcome is claimed with an atomic update of `reservation_units.welcome_sent_at`, so the check-in, the PIN sync and the deadline can all ask and it is sent exactly once per check-in. A stay checked in again after an undo has a newer `checked_in_at` and is welcomed again. The deadline carries the check-in time, so the deadline of an undone check-in never cuts the wait of the next one short.

The welcome is two messages from one moment: the `ConciergeStayLink` email (to the guest's address) and the `stay.welcome_ready` webhook, which a WhatsApp automation can listen to (see [Outgoing webhooks](../32-webhooks/01-outgoing-webhooks.md)). Each makes a new link; older links stay valid until they expire. Nothing is sent, and no link is made, while the property has the Concierge feature off. The email is skipped when the guest has no address, and the webhook when no endpoint hears it. The email is a plain notification, like the online booking confirmation, so it skips the preference matrix.

The webhook carries the door PIN, so an admin who opens the delivery Sheet sees it, as they already see the PIN on the stay.

## Returning guest

When a guest opens their stay page, the phone remembers the stay link (`localStorage`, one entry per property, never sent to the server). The lobby then shows **Continue your stay** at the top for 8 days. A stay that ends at the front desk (the desk step, or an expired hold) clears the entry, so the tile does not lead to a dead end. If the browser blocks storage, the guest types the booking code again.

## Property guide and contact

Public from the hub and, with more in it, from the stay link. The owner keeps the content in Settings, Concierge.

| Section      | Where it comes from                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| ------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| The property | Name, address, check-in and check-out times and the map link (the owner's link, or one made from the coordinates). Then, each only when filled: **About** the property (`properties.about`, from the property profile), **Arrival** and **Departure** instructions (`property_settings`), **Getting here** (`property_transport_details`: directions, distance and travel time to the airport, station and city centre, nearest stop, airport transfer with price and lead time, pickup point, shuttle, **parking**: availability, type, price and unit, spaces, height limit, EV charging, booking required and notes) and extra topics under their own title (`property_info_topics`, up to 20, only the ones switched on)                                  |
| Facilities   | The amenities the property ticked, by category (the booking engine's registry), with the details the owner wrote for each: where, opening hours, cost, reservation and a note (`property_amenities`). Un-ticking an amenity removes its details                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| Policies     | Check-in and check-out times, **Smoking** and **pets** facts (read from the room types), the **house rules**, **room terms**, **payment and deposits** and **other policies** texts (`property_settings`, up to 2000 characters each, shown only when filled), and the owner's guest wording of the cancellation, no-show, late check-out and early departure policies (`text_en` or `text_ms` of the Room revenue policy, in Malay when the property default is Malay). No charge amounts are shown                                                                                                                                                                                                                                                          |
| FAQs         | Questions and answers the owner lists (`property_faqs`, up to 30), each under an optional topic heading. The guide groups them by an exact match on the topic, in the order of each topic's first question. Staff manage them in a table grouped the same way: a Hide/Show switch per row (`SetFaqActive`, logs `concierge.faq-shown` or `concierge.faq-hidden`; a question that is off is hidden from guests but still counts toward the 30), and a row menu with Edit (a dialog, `UpdateFaq`, logs `concierge.faq-updated` with the changed fields) and Delete (asks first). The topic field suggests the topics already used, so a new question joins an existing group with the same spelling. The guest page has a search box once there are six or more |
| Wi-Fi        | Networks the owner lists (`property_wifi_networks`, up to 10, password encrypted at rest). Staff manage them in a table: a Hide/Show switch per row (`SetWifiNetworkActive`, logs `concierge.wifi-shown` or `concierge.wifi-hidden`), and a row menu with Edit (a dialog for name, password, note and main), Set as main (`UpdateWifiNetwork`, logs `concierge.wifi-updated` with the changed fields, never the password) and Delete (asks first). One network can be the **main** one, listed first. A network that is off is **staff only**: a guest never sees it. A network with no password shows as open. Only a guest whose stay is **checked in** sees it, and never a lobby visitor                                                                  |
| Contact      | The **front desk** phone, extension (to dial from a room phone) and email (`property_settings`), then phone (a call link), email (a mail link), address and the map link. Also the front desk hours with an open or closed line (the property time zone), a WhatsApp link, the **duty manager** phone and the out-of-hours message (both only while the desk is closed), and the **emergency** security phone, emergency services number and instructions                                                                                                                                                                                                                                                                                                     |
| Book a room  | A link to the booking website, shown only when the property has the Booking engine on and a published microsite                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |

## Documents and refund requests

- **Documents** are PDFs made on demand by the builders staff use (`BookingSummary`, `PaymentReceipt`, `FolioInvoice`), and nothing is stored. A guest gets the booking summary, a receipt for each payment on the stay's own folios, and the final invoice once the folio is closed. A guest never reaches another stay's receipt. They open while the stay is checked in or checked out.
- **A refund request** is a [guest request](../25-guest-requests/01-guest-requests.md) of the type Refund with the source Concierge. The owner turns the switch on in Settings, Concierge. A guest can send it only after check-out, and only one at a time; staff answer it in the Requests list. Nothing is refunded automatically.

## E-invoice request

A guest asks for their own LHDN e-invoice for the stay at `/c/{token}/e-invoice` (`ConciergeEInvoiceController`). The stay page links to it after check-out while the property offers one. It uses the same buyer fields as staff's **Issue e-invoice** form ([LHDN MyInvois](../31-einvoice/01-lhdn-myinvois.md#buyer-resolution-rules)), for a person: name, NRIC or passport, tax number (TIN), country, address, and a Malaysian state for a Malaysian address. A foreign guest may leave the TIN empty (LHDN's general foreign number is used).

- **Open only while** the stay's folio is Closed with an invoice number, nothing holds the folio yet (`FolioEInvoiceLock`), the property has e-invoicing on with MyInvois credentials, a person (not a company) pays, and the current month is the month the folio closed (Malaysia time). After that month the stay may be in the monthly summary, so the page says to ask the front desk.
- **The request** is `RequestConciergeEInvoice`, which calls `SubmitFolioEInvoice` with no staff actor, `requested_by_guest` on and the typed buyer, so the e-invoice follows every rule of a staff send (one per stay, the hotel's details, LHDN's checks). Guest-fixable refusals show their reason; anything about the hotel's own details shows one plain line.
- **States** (`ConciergeEInvoiceState`): after check-out, unavailable, window closed, in summary, open, processing and valid. A Valid e-invoice shows an **Open the e-invoice** link to the public MyInvois page. A Rejected request opens the form again with what the guest typed and a short note.
- **Privacy.** The form never shows a stored ID number: the ID number, tax number, email, phone and street address start blank, unless the guest typed them on a request LHDN rejected. Only the name, country, city, state and postcode are prefilled. The page never shows LHDN's raw replies or the document.
- **Not built:** emailing the e-invoice to the guest from this page (staff can email it from the submission page), and the **Check with LHDN** tax number check, which needs a staff login.

## Compared with wastays

The wastays Guest Concierge was the reference for what a guest needs. This table says where each part stands in estatell. Estatell does not copy its screens or its security model.

| wastays supplies                                                    | Estatell                                                                                                                                                                       |
| ------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Public hub from the hotel QR                                        | Built: the lobby hub                                                                                                                                                           |
| Pre-check-in (lookup, details, ID)                                  | Built, and further: the guest checks in and gets a door PIN on the page                                                                                                        |
| Book a room                                                         | Built: a link to the booking website when it is published                                                                                                                      |
| Contact: phone, email, map                                          | Built, with front desk hours, WhatsApp, duty manager and emergency contacts                                                                                                    |
| Property guide: property, amenities, policies, FAQs                 | Built, with About, arrival and departure, getting here, extra topics, and the policy wording                                                                                   |
| Wi-Fi for an in-house guest                                         | Built                                                                                                                                                                          |
| Recommendations: places                                             | Built as nearby places                                                                                                                                                         |
| Stay page: housekeeping and report an issue                         | Built as requests                                                                                                                                                              |
| Check out                                                           | Built                                                                                                                                                                          |
| Booking receipt, invoice and summary PDFs                           | Built                                                                                                                                                                          |
| E-invoice request                                                   | Built: the guest types the buyer details and `SubmitFolioEInvoice` sends the stay's e-invoice                                                                                  |
| Ask for a refund, and its status                                    | Built: a refund request, and the guest sees its status after check-out                                                                                                         |
| Walk-in booking confirmation emailed after payment or ID step       | Built: `AnnounceWalkInBooking` (after payment, or after the ID step when nothing is owed up front) emails the booking number and check-in link with `ConciergeWalkInConfirmed` |
| Stay link emailed after check-in                                    | Built: `SendConciergeStayLink` emails the guest the stay link when any channel checks them in                                                                                  |
| Device check with the confirmation code, lockout and email recovery | Different: the stay link is the secret, and the lobby code lookup has its own lockout                                                                                          |
| 7 days of access after check-out                                    | Built: the link works until 7 days after the departure date                                                                                                                    |
| Hotel photo hero and menu style                                     | Built as a hero photo. The menu style is a design choice, so it is not built                                                                                                   |

## Lobby hero photo

The large photo beside the property name on the lobby hub and on the booking-code page. Staff upload it in Settings, Concierge, Overview, on the **Lobby photo** card in the rail (JPEG, PNG or WebP, up to 5 MB, one per property; it saves at once). It is stored in the `hero` media collection of the property on the public media disk, so guests read it with no login. With none uploaded, or after **Use stock photo**, both pages show the stock coast photo (`public/images/concierge-editorial-coast.jpg`). Upload and removal are logged as `concierge.settings-updated`.

## Guest recovery and remaining screens

Registration uses a responsive terms-and-signature layout with the existing signature pad, agreement requirement and signed terms version. Walk-in offers show the server-priced stay total, with a separate no-availability state. Not-open and expired-hold screens provide a centered explanation and a next action; refund requests show their status and the front desk reply together.

Browser errors under `/stay/*` and `/c/*` use the concierge theme. Unknown and expired stay links return the same 404 page without property or booking details. Recovery links remain within concierge routes; staff, external, malformed and failed-page referrers are discarded. A 503 retry revisits the guest URL as a GET and never replays a submission. Staff and API error responses keep their existing behavior.

## Walk-in

A walk-in is a booking made through the kiosk's own rules (`BookWalkIn::book`, shared with the kiosk): the server prices it from the property's rates, a Confirmed booking and a unit folio are made, and the room is held for 10 minutes. **The Concierge page makes none of this until the guest has given who they are.** The guest picks a room (`GET /stay/{code}/walk-in`, nothing written), opens the details page for it (`GET /stay/{code}/walk-in/details`, which checks the room is still free and writes nothing), and sends the details (`POST /stay/{code}/walk-in`, with the room choice in the same request). `ConfirmConciergeWalkIn` then books and holds the room and saves the details (`SaveConciergeIdentity`) in **one transaction**, so a booking is born with the guest's name: no nameless booking ever exists, in the database or on any staff screen, and a guest who walks away leaves nothing behind. If any step refuses (the room just went, too many holds are open, the nationality a tax needs is missing, the ID is refused), nothing is written and the guest is told on the page. The cost is that the room is **not held while the guest types**: `BookConciergeWalkIn` checks it is still free at the moment they send the details, and a guest whose room has gone is sent back to the search with a message. A stay that owes nothing up front is announced at once (the booking email and `booking.created`); one that owes a deposit or the stay waits for its first payment. The guest then follows the same steps as any other guest, including payment. An unpaid hold is released by `ReleaseExpiredKioskHolds`. The walk-in's `booking.created` webhook waits until the hold is kept (see [Outgoing webhooks](../32-webhooks/01-outgoing-webhooks.md#events)). The kiosk keeps its own order (hold first, ID after).

### Returning guests

A returning guest does not retype their details. The details page of a walk-in first shows **Stayed with us before?**: one box for the email or the phone number the guest used before (an `@` makes it an email, anything else is read as a phone number and normalised like the matcher does), sent to `POST /stay/{code}/walk-in/returning` with the room picked. `ReturningGuest::match` looks for a named guest of the property with that email or phone (`GuestMatcher`) **before anything is booked**. Exactly one named profile should have it. When several do, they count as duplicates of one guest only if every one of them with a stay has the same name: the one with the most stays (the newest on a tie) is joined. A phone or an email shared by profiles with different names (a family phone), or by profiles with no stays, joins no one, and neither does a profile with no name; staff merge those guests. If one is found, `ConfirmConciergeWalkIn` books the room **for that profile** (`BookWalkIn::book` takes the guest), so the guest's history and flags carry over, no second guest is made and nothing is merged; `concierge.returning_guest_joined` is logged on the profile with the stay id, never the email or the number. The ID step is done when that profile already has an ID document (and a nationality, where a tax needs it); otherwise the stay page says the room is booked (with the reference) and asks for the ID alone. It never shows the profile's values, and never asks again for a name, phone or email the profile already holds: the page gets only flags (`hideKnown`, `hasName`, `hasPhone`, `hasEmail`), the date of birth and gender are not asked, and nationality is asked only when a tax needs it. A detail the profile lacks is still asked, and the ID is saved on that profile (saved details are kept, never replaced). With no match **nothing is booked** and the page shows the full details form with what was typed kept; it never says whether a profile exists. **Decided 2026-11-19:** an email or a phone is easy to know, so this check is weaker than the ID number with date of birth it replaced (and a guest can be matched without proving who they are). It is accepted for convenience, with the throttle below as the only guard. After a match the page greets the guest by the first name on that profile ("Welcome, Aisha"), so anyone who types a known email or phone learns that first name; nothing else of the profile is shown (decided 2026-11-19). Only that throttled check joins a guest: the full details form never does, and neither does a name. `POST /stay/{code}/walk-in/returning` allows 5 tries an hour per property and address, and 20 an hour per address. The details form also takes a phone (saved as E.164) and an email. A booking made earlier already has a named guest, so it is never merged this way. The card signature is still asked each stay. A one-time code to the saved email is a possible later step if guessing becomes a problem.

### Day use

A property that sells day use (an active room type sold per room and an active Day use plan for it) shows an **Overnight / Day use** switch on the walk-in page. A day use search takes adults, a start time and whole hours (1 to 12) for today. `ConciergeDayUse` offers each room type at the cheapest Day use plan that prices those hours today, inside the plan's Sold between window and hour limits, with no stop sell, and with a room free for the block (`DayUseAvailability`). The search opens on the half hour running now, and a guest may start in it: at 15:02 a 15:00 start is offered, so a room freed at 15:00 sells at once. An earlier start is never offered, and the plan's window and hour limits still apply. A Dirty room is never offered or held, because no staff member is there to check it and an early day use check-out frees its room at once (decided 2026-11-18). Housekeeping marks the room Clean to sell it again. Booking holds the first free room for 10 minutes, with the same payment, release and check-in as a night walk-in. `AddUnit` accepts a day use line without a staff member only when the booking source is Walk-in, so the booking engine and channels still cannot sell it. A typed price is staff-only, so a plan is required. The stay page shows 0 nights for a day use stay.

- **Location check:** a property can switch on `property_settings.concierge_location_check_enabled` (off by default). Then, when the property has coordinates, the page asks the browser for the guest's position and `CheckInConciergeStay` refuses `location_required` (none sent) or `too_far_away` (more than 50 m from the property). A property with no coordinates, or with the switch off, asks nothing.
- The walk-in switch is `property_settings.concierge_walk_in_enabled`, off by default and separate from the kiosk's switch.
- The arrival is always the business date. A search is 1 to 8 adults and 1 to 14 nights, with no children.
- At most `BookConciergeWalkIn::MAX_OPEN_HOLDS` (10) holds can be open at once, and a booking is throttled to 6 per 10 minutes per address.

## Safety

The page can issue a door PIN to someone who holds a QR code, so it is stricter than the kiosk, which stands in the lobby.

- The QR proves only the property. The stay is proved by the booking code alone, or by the stay's own link. The code is random (about 887 million per property) but anyone with the lobby address can try codes; a stay they open gives them its ID step, and after check-in the room and PIN. The lockout is the only guard: raise it, or add a second factor, if this becomes a worry.
- A lookup takes one attempt from two counters: one per address and property (8 in 5 minutes), one per booking code (5 in 15 minutes). A lookup that finds its stay gives both back. An address may also open at most 15 stays in 10 minutes, because a scan down the booking codes finds a stay almost every time and the refund would never slow it. Once a counter is passed the answer is the same whatever was typed.
- Every code that opens nothing gets the same message, so it never says whether a code exists.
- The page shows the room and the PIN only for 30 minutes after it checked the guest in. It never shows them for a stay another channel checked in.
- Check-in uses `CheckInUnit`, so the card, nationality, room and arrival rules are the front desk's. The paid-enough rule runs first.
- The page sends the guest's first name only. Logs hold ids and field names, never values.

## What it reuses

| Need              | Piece                                                                      |
| ----------------- | -------------------------------------------------------------------------- |
| Check-in rules    | `CheckInUnit`, `KioskEligibility`, `SelfCheckInPayment`                    |
| Registration card | `SignStayRegistrationCard`, `KioskRegistrationTerms`                       |
| Stay proof        | `BookingPreCheckInToken`, `IssueBookingPreCheckInToken`                    |
| PIN               | `KioskPin` (reads the TTLock passcodes the check-in queued)                |
| Payment           | `CurlecGateway`, `RecordPayment`, `ProcessCurlecEvent`, the refund Actions |
| Walk-in           | `BookWalkIn::book`, `KioskWalkIn::available`, `KioskWalkInHold`            |

New: the `Concierge` feature, `ConciergeStays`, `ConciergeLookupLockout`, `ConciergeProgress`, `ConciergePageBuilder`, `ConciergeLobbyQr`, and the Actions `OpenConciergeStay`, `SaveConciergeIdentity`, `CheckInConciergeStay`, `StartStayPayment`, `ConfirmStayPayment`, `RecordStayPayment`, `BookConciergeWalkIn`, `UpdateConciergeSettings`, `UploadConciergeHero`, `RemoveConciergeHero`.

## Not built

- **Facility opening hours and fees.** The registry holds names only.
- **Cancellation terms in the policies page.** They are rate-specific and live on the booking.
- **Cards for other guests** of a booking, and choosing one room of a multi-room booking from a booking code.
- **Early check-out, late check-out and extending a stay** from the page. They are the desk's.
- **ID photo reading.** The guest types the ID fields. A photo is stored for staff to see and fills nothing.
- **Sending the stay link by WhatsApp.** The email is built.
- **Telling staff** when a request arrives. The request shows in the staff list, with no notification yet.
- **AI chat and future-date booking.** Planned in [AI assistant](../34-ai-assistant/01-overview.md): a chat on the lobby and stay pages, and [quote links](../34-ai-assistant/03-places-and-routes.md#quote-links) that book future dates without the microsite.

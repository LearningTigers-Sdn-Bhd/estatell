# Concierge page

## Purpose

A page on the guest's own phone that checks the guest in with no front desk and no kiosk hardware, and then carries the stay. Check-in is the product the landing page calls Self Check-in: scan the lobby QR, give an ID, sign, pay online, receive a smart-lock PIN. After check-in the same link opens the stay pages: ask the front desk for something, see the facilities and nearby places, see and pay the bill, and check out.

The kiosk is a separate product that needs hardware. The page shares the check-in rules with the kiosk and nothing else: not its token, not its actor, not its routes.

**Status: built.** The lobby hub, the property guide, contact, nearby places, self check-in, the in-stay pages, the bill with check-out, documents and refund requests are built. The e-invoice request comes later. See [Not built](#not-built) and [Compared with wastays](#compared-with-wastays).

## Guest design

Guest concierge pages retain the locked editorial identity: warm paper, olive primary actions and fine borders. The landing page keeps its editorial typography. Inner pages use standard Estatell controls, rounded buttons, cards, badges and content typography. The landing page keeps its full-screen composition, angled coastal artwork, desktop separators and mobile check-in action below the image. Other pages share `GuestFrame`, with property identity, a back link, the standard Estatell `PageHeader` and responsive content. The property guide uses `TabLinks` for its route-based chapters. Arrival times and location appear first; travel and parking facts, policies, facility details, FAQs and Wi-Fi are grouped into readable sections. The plain guest navbar stays hidden on concierge routes.

The theme is scoped to concierge pages, including portalled form controls. Staff screens and the separate registration-card pages keep their existing design. Forms, validation, payments, signatures and room-key visibility rules do not change.

Guest service pages group request forms with their history, contact methods with front desk hours, and bill entries with check-out actions. The guide keeps route-based chapters, readable arrival times, facility details and Wi-Fi instructions. Empty sections explain how to get help without inventing property information. Guest booking summaries, receipts and invoices use olive headings and shaded information strips and totals; they retain the property's print fonts and document content. Staff PDFs keep their configured appearance.

## Decisions

| Area            | Decision                                                                                                                                                                                                    |
| --------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Where it lives  | Inside estatell, in `routes/concierge.php`, outside `kiosk/v1`. No separate site and no new API                                                                                                             |
| Feature flag    | `Concierge`, per property, off by default, set by admins. Every Concierge route answers 404 while it is off or the property is suspended                                                                    |
| Lobby QR        | One per property. It opens `/stay/{code}`, a hub of services (check in, book a room, property guide, nearby places, contact) that is public and carries no booking. Staff print it from Settings, Concierge |
| Stay link life  | The stay's link (`/c/{token}`) works until 7 days after the departure date, so a guest can still open their documents and ask for a refund                                                                  |
| Who can use it  | Guests with a booking, and walk-ins when the property turns the walk-in switch on                                                                                                                           |
| Proof of a stay | The booking code alone, or the stay's own link `/c/{token}`. The link is the stay's pre-check-in token. Codes run in order, so a code can be guessed: the lockout slows guessing and does not stop it       |
| Payment         | Online through Razorpay Curlec. It pays what the kiosk's paid-enough rule wants: the Collect now taxes and the security deposit                                                                             |
| Key             | A smart-lock PIN shown on the page after check-in. No key card                                                                                                                                              |
| Actor           | The page is its own channel. Its log events are `concierge.*`, never `kiosk.*`                                                                                                                              |
| Multi-room      | A booking code that matches more than one room opens nothing. Each room uses its own link                                                                                                                   |
| Other guests    | The page signs the primary guest's card only. A card still missing for another guest sends the guest to the desk                                                                                            |

## Flow

```
 Lobby QR  ──►  /stay/{code}  ──►  booking code ───────────────────────┐
                    │                                                    ▼
                    └──► no booking? ──► /stay/{code}/walk-in ──► room ──► /c/{token}
                                                                           │
   ID  ──►  registration card (if required)  ──►  payment (if due)  ──►  check in  ──►  room + PIN
```

`/c/{token}` is one page. `ConciergeProgress` reads the stay and picks the step, so a guest can leave and come back to the same link and land on the right step.

| Step           | Shown when                                                                                         |
| -------------- | -------------------------------------------------------------------------------------------------- |
| `identity`     | The guest has no ID document, or an enabled foreign-only tax needs a nationality                   |
| `card`         | The property requires the card for check-in and the primary guest has not signed                   |
| `payment`      | `SelfCheckInPayment::shortfall` is more than zero                                                  |
| `check_in`     | Everything above is done                                                                           |
| `key`          | The page checked the stay in during the last 30 minutes. It shows the room and the PIN             |
| `done`         | The stay is checked in by another channel, or the 30 minutes ended. No room and no PIN             |
| `not_open`     | Before the arrival day                                                                             |
| `hold_expired` | A walk-in's unpaid hold was released                                                               |
| `desk`         | Anything the guest cannot fix: a closed stay, no guest, a card missing for another guest, no terms |

## Routes

| Route                                  | Name                         | Purpose                                       |
| -------------------------------------- | ---------------------------- | --------------------------------------------- |
| `GET/POST /stay/{code}`                | `concierge.lobby`, `.find`   | Lobby, and open a stay by booking code        |
| `GET/POST /stay/{code}/walk-in`        | `concierge.walk-in`, `.book` | Offers for tonight, and book one              |
| `GET /c/{token}`                       | `concierge.show`             | The stay page                                 |
| `POST /c/{token}/identity`             | `concierge.identity`         | Save the typed ID and optional photos         |
| `POST /c/{token}/sign`                 | `concierge.sign`             | Sign the primary guest's registration card    |
| `POST /c/{token}/payment`              | `concierge.payment`          | Start the Curlec order. JSON for the checkout |
| `POST /c/{token}/payment/confirm`      | `concierge.payment.confirm`  | Confirm a finished checkout by its signature  |
| `POST /c/{token}/check-in`             | `concierge.check-in`         | Check in with the front desk's rules          |
| `GET/PUT /p/{code}/settings/concierge` | `settings.concierge.*`       | Staff: lobby QR and the walk-in switch        |

All guest routes sit behind the `concierge` middleware (`EnsureConciergeIsActive`) and are throttled. A write through a link works only on a Confirmed or CheckedIn stay; a link to a cancelled stay can only be read, to show `hold_expired`.

## Payment

A Curlec order is for a booking hold or for a stay. `gateway_payments.reservation_unit_id` is set for a stay order, and exactly one of it and `booking_hold_id` is set (a check constraint). Refunds, the webhook and the alerts treat both kinds the same way.

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

- The bill is the sum of the stay's unit folios. A line is a charge or tax (positive) or a payment (negative).
- **Owing money:** the guest pays the whole open bill online. It is the same Curlec order and webhook as the check-in payment, and it posts a Settlement (before check-in it posts an Advance).
- **A credit or held deposit:** the page shows it and tells the guest the front desk gives it back. A stay with a deposit settles to zero only after the desk returns it, so the guest checks out at the desk.
- **Check-out:** offered when the bill is zero and the check-out window is open, the same window the kiosk uses (the departure day). `CheckOutConciergeStay` calls the front desk's `CheckOutUnit` with no staff actor, logs `concierge.checked_out` with the property id only, and answers the same result again on a retry. An earlier check-out is the desk's.
- **Asking the desk:** when the guest cannot check out alone (money is owed, a deposit is held, or it is before the departure day), the bill page offers **Ask the front desk to check you out** with an optional note. It becomes a guest request of the type Other whose details start with `Check-out request` (`RequestConciergeCheckOut`), in the staff Requests list. One can be open at a time. While it is open the bill and the stay page show "Check-out asked".
- After check-out the link shows the thank-you, the documents and, when a refund request was sent, how it is going. Every write through it answers 404.

## Stay link email

When a stay checks in, by the page, the front desk or the kiosk, `SendConciergeStayLink` (a listener of `StayCheckedIn`) makes a new link and emails it to the guest with `ConciergeStayLink`. Nothing is sent while the property has the Concierge feature off or when the guest has no email address. The email is a plain notification to the address on the guest, like the online booking confirmation, so it skips the preference matrix. Older links stay valid until they expire.

## Returning guest

When a guest opens their stay page, the phone remembers the stay link (`localStorage`, one entry per property, never sent to the server). The lobby then shows **Continue your stay** at the top for 8 days. A stay that ends at the front desk (the desk step, or an expired hold) clears the entry, so the tile does not lead to a dead end. If the browser blocks storage, the guest types the booking code again.

## Property guide and contact

Public from the hub and, with more in it, from the stay link. The owner keeps the content in Settings, Concierge.

| Section      | Where it comes from                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| ------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| The property | Name, address, check-in and check-out times and the map link (the owner's link, or one made from the coordinates). Then, each only when filled: **About** the property, **Arrival** and **Departure** instructions (`property_settings`), **Getting here** (`property_transport_details`: directions, distance and travel time to the airport, station and city centre, nearest stop, airport transfer with price and lead time, pickup point, shuttle, **parking**: availability, type, price and unit, spaces, height limit, EV charging, booking required and notes) and extra topics under their own title (`property_info_topics`, up to 20) |
| Facilities   | The amenities the property ticked, by category (the booking engine's registry), with the details the owner wrote for each: where, opening hours, cost, reservation and a note (`property_amenities`). Un-ticking an amenity removes its details                                                                                                                                                                                                                                                                                                                                                                                                   |
| Policies     | Check-in and check-out times, **Smoking** and **pets** facts (read from the room types), the **house rules**, **room terms**, **payment and deposits** and **other policies** texts (`property_settings`, up to 2000 characters each, shown only when filled), and the owner's guest wording of the cancellation, no-show, late check-out and early departure policies (`text_en` or `text_ms` of the Room revenue policy, in Malay when the property default is Malay). No charge amounts are shown                                                                                                                                              |
| FAQs         | Questions and answers the owner lists (`property_faqs`, up to 30), each under an optional topic heading. The guest page has a search box once there are six or more                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| Wi-Fi        | Networks the owner lists (`property_wifi_networks`, up to 10, password encrypted at rest). One can be the **main** network, listed first, and one can be **staff only** (`active` off), which a guest never sees. Both switches can be changed on the network's row (`UpdateWifiNetwork`). A network with no password shows as open. Only a guest whose stay is **checked in** sees it, and never a lobby visitor                                                                                                                                                                                                                                 |
| Contact      | The **front desk** phone, extension (to dial from a room phone) and email (`property_settings`), then phone (a call link), email (a mail link), address and the map link. Also the front desk hours with an open or closed line (the property time zone), a WhatsApp link, the **duty manager** phone and the out-of-hours message (both only while the desk is closed), and the **emergency** security phone, emergency services number and instructions                                                                                                                                                                                         |
| Book a room  | A link to the booking website, shown only when the property has the Booking engine on and a published microsite                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |

## Documents and refund requests

- **Documents** are PDFs made on demand by the builders staff use (`BookingSummary`, `PaymentReceipt`, `FolioInvoice`), and nothing is stored. A guest gets the booking summary, a receipt for each payment on the stay's own folios, and the final invoice once the folio is closed. A guest never reaches another stay's receipt. They open while the stay is checked in or checked out.
- **A refund request** is a [guest request](../25-guest-requests/01-guest-requests.md) of the type Refund with the source Concierge. The owner turns the switch on in Settings, Concierge. A guest can send it only after check-out, and only one at a time; staff answer it in the Requests list. Nothing is refunded automatically.

## Compared with wastays

The wastays Guest Concierge was the reference for what a guest needs. This table says where each part stands in estatell. Estatell does not copy its screens or its security model.

| wastays supplies                                                    | Estatell                                                                                      |
| ------------------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| Public hub from the hotel QR                                        | Built: the lobby hub                                                                          |
| Pre-check-in (lookup, details, ID)                                  | Built, and further: the guest checks in and gets a door PIN on the page                       |
| Book a room                                                         | Built: a link to the booking website when it is published                                     |
| Contact: phone, email, map                                          | Built, with front desk hours, WhatsApp, duty manager and emergency contacts                   |
| Property guide: property, amenities, policies, FAQs                 | Built, with About, arrival and departure, getting here, extra topics, and the policy wording  |
| Wi-Fi for an in-house guest                                         | Built                                                                                         |
| Recommendations: places                                             | Built as nearby places                                                                        |
| Stay page: housekeeping and report an issue                         | Built as requests                                                                             |
| Check out                                                           | Built                                                                                         |
| Booking receipt, invoice and summary PDFs                           | Built                                                                                         |
| E-invoice request                                                   | Later                                                                                         |
| Ask for a refund, and its status                                    | Built: a refund request, and the guest sees its status after check-out                        |
| Stay link emailed after check-in                                    | Built: `SendConciergeStayLink` emails the guest the stay link when any channel checks them in |
| Device check with the confirmation code, lockout and email recovery | Different: the stay link is the secret, and the lobby code lookup has its own lockout         |
| 7 days of access after check-out                                    | Built: the link works until 7 days after the departure date                                   |
| Hotel photo hero and menu style                                     | Partly: the hub shows the property logo and name. No hero photo field exists                  |

## Walk-in

A walk-in is a booking made through the kiosk's own rules: the server prices it from the property's rates, a Confirmed booking and a unit folio are made at once, and the room is held for 10 minutes (`BookWalkIn::book`, shared with the kiosk). The guest then follows the same steps as any other guest, including payment. An unpaid hold is released by `ReleaseExpiredKioskHolds`.

- **Location check:** a property can switch on `property_settings.concierge_location_check_enabled` (off by default). Then, when the property has coordinates, the page asks the browser for the guest's position and `CheckInConciergeStay` refuses `location_required` (none sent) or `too_far_away` (more than 50 m from the property). A property with no coordinates, or with the switch off, asks nothing.
- The walk-in switch is `property_settings.concierge_walk_in_enabled`, off by default and separate from the kiosk's switch.
- The arrival is always the business date. A search is 1 to 8 adults and 1 to 14 nights, with no children.
- At most `BookConciergeWalkIn::MAX_OPEN_HOLDS` (10) holds can be open at once, and a booking is throttled to 6 per 10 minutes per address.

## Safety

The page can issue a door PIN to someone who holds a QR code, so it is stricter than the kiosk, which stands in the lobby.

- The QR proves only the property. The stay is proved by the booking code alone, or by the stay's own link. A code is easy to guess because codes run in order, so anyone with the lobby address can try codes; a stay they open gives them its ID step, and after check-in the room and PIN. The lockout is the only guard: raise it, or add a second factor, if this becomes a worry.
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

New: the `Concierge` feature, `ConciergeStays`, `ConciergeLookupLockout`, `ConciergeProgress`, `ConciergePageBuilder`, `ConciergeLobbyQr`, and the Actions `OpenConciergeStay`, `SaveConciergeIdentity`, `CheckInConciergeStay`, `StartStayPayment`, `ConfirmStayPayment`, `RecordStayPayment`, `BookConciergeWalkIn`, `UpdateConciergeSettings`.

## Not built

- **The e-invoice request.** It comes later.
- **Facility opening hours and fees.** The registry holds names only.
- **Cancellation terms in the policies page.** They are rate-specific and live on the booking.
- **Cards for other guests** of a booking, and choosing one room of a multi-room booking from a booking code.
- **Early check-out, late check-out and extending a stay** from the page. They are the desk's.
- **ID photo reading.** The guest types the ID fields. A photo is stored for staff to see and fills nothing.
- **Sending the stay link by WhatsApp.** The email is built.
- **Telling staff** when a request arrives. The request shows in the staff list, with no notification yet.
- **A hero photo and a menu style** per property.

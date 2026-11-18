# Facilities and events

> **Planned.** Not built. Planned on 2026-11-18 for a two-lodge client (Sepilok and Kinabatangan). The build items are in [checklist.md](../checklist.md#33-facilities). Assumptions to confirm with the client are at the end.

## Purpose

Lodges and resorts sell more than rooms: an event hall, a BBQ pit, kayaks, a River Safari, a Night Walk. Staff need to:

1. Book these by date and time without clashes.
2. See them next to the rooms on the Timeline View.
3. Bill them to the right folio, also for people who are not staying (a wedding, a walk-in lunch, a kayak hire).

## Terms

| Term             | Means                                                                                                    |
| ---------------- | -------------------------------------------------------------------------------------------------------- |
| Facility         | Something staff book by date and time: a hall, a BBQ pit, kayaks, an activity                            |
| Private          | One booking takes the whole facility. Bookings cannot overlap                                            |
| Shared           | Several bookings share the facility until it is full                                                     |
| Facility booking | One booking of one facility, for a time, a quantity and a folio                                          |
| Event            | A bill for people who are not staying: a wedding, a meeting, a walk-in. It has one event folio           |
| Event folio      | A folio that belongs to an event, not a reservation                                                      |
| Amenity          | What the property offers, shown to guests (`property_amenities`, the concierge and booking engine pages) |

A facility is not an amenity. An amenity is a fact shown to guests ("Swimming pool, 07:00–19:00"). A facility is booked by staff. One place can be both. They are not linked in this plan.

**Naming (decided 2026-11-18):** both names stay. Staff edit amenities in Settings, Concierge, **Amenities**, and facilities in Settings, **Facilities**, so staff never see the two under one name. Guest pages keep the label "Hotel facilities" for amenities and never show bookable facilities. In staff UI and code, use **Amenity** for what is shown to guests (`property_amenities`) and **Facility** for what is booked (`facilities`).

"Private" and "Shared" follow Bookinglayer, which marks rooms Private (P) or Shared (S).

## Two cases

| Who books                                     | Owner of the facility booking | Folio                                                                                                           |
| --------------------------------------------- | ----------------------------- | --------------------------------------------------------------------------------------------------------------- |
| A hotel guest or group                        | The **reservation**           | Any Open folio of that reservation, picked in **Charge to**: Master, a room's folio, or a new third-party folio |
| Nobody staying (wedding, walk-in, kayak hire) | An **event**                  | The event's folio                                                                                               |

A reservation never creates an event or an event folio. A group's dinner that someone else pays goes to a [third-party folio](../13-folio/03-routing.md#folio-types) of the reservation.

Activities (River Safari, Night Walk) are Shared facilities. The [Facility bookings report](05-timeline-and-report.md#facility-bookings-report) is their participant list.

## Feature flag

Pennant feature `Facilities` (`App\Features\Facilities`, `#[Name('facilities')]`), off by default, like [Boats](../24-boats/01-boat-settings.md#feature-flag). A platform admin turns it on per property. `PlatformFeature::Facilities` lists it. With the feature off, the settings item, the Events pages, the reservation Facilities tab and the Timeline View section are hidden, and the routes return 404 through a small `facilities` middleware. Turning it off keeps all data.

## Files

| File                                                     | Covers                                                               |
| -------------------------------------------------------- | -------------------------------------------------------------------- |
| [Facility settings](02-facility-settings.md)             | `facilities`, Private and Shared, the price, the settings page       |
| [Events and event folios](03-events-and-event-folios.md) | `events`, the event folio, the Events list and the event page        |
| [Facility bookings](04-facility-bookings.md)             | `facility_bookings`, the clash check, Charge to, posting, the dialog |
| [Timeline and report](05-timeline-and-report.md)         | The Facilities section on the Timeline View and the report           |

## Permissions

| Permission        | Allows                                                                |
| ----------------- | --------------------------------------------------------------------- |
| `settings.manage` | Facility settings (existing)                                          |
| `events.view`     | The Events list and event pages                                       |
| `events.manage`   | Create, edit, confirm and cancel events                               |
| `facilities.book` | Book, change and cancel facility bookings, on events and reservations |

Folio actions on an event folio use the existing folio permissions (`folio.post`, `payment.record` and so on). New permissions are added with a syncing migration, as for other modules.

## Assumptions to confirm with the client

We recommend yes to both. Questions to ask:

| #   | Assumption                                                                                                                 | Question for the client                                                                                                                                         | If the answer is no                                                               |
| --- | -------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------- |
| 1   | Activities (River Safari, Night Walk) are Shared facilities. Assigning a guide or a boat to a session is not in this plan. | "Should River Safari and Night Walk work like a shared facility: sessions with a headcount limit and a participant list? Guide or boat assignment comes later." | Activities drop out. Facilities covers halls and equipment only.                  |
| 2   | Halls can be held as **Tentative** before the deposit is paid. Tentative does not block; Confirmed does.                   | "Do you hold the hall for a client before the deposit is paid?"                                                                                                 | Tentative goes away. Every booking blocks, and `EventStatus` starts at Confirmed. |

## Not in this plan

- Linking a facility to an amenity.
- Automatic cancellation fees for events. Staff refund or keep a deposit by hand.
- Auto-expiry of Tentative holds.
- Guides, boats or crew per activity session.
- Selling facilities on the booking engine or channels.

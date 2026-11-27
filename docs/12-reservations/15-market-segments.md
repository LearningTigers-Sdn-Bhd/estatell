# Market segments

## Purpose

Sort bookings by market (Leisure, Corporate, Government, Groups...), apart from where they came from ([booking source](11-booking-sources.md)) and who sold them ([sales person](12-sales-person.md)). Tanini's arrival report has a Market Segment column (requirement 7.1). The owner chose to add it on 2026-11-21.

**Status: built** (2026-11-22).

## Decisions

| Decision   | Rule                                                                                                                                   |
| ---------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| List       | Per property, in **Settings › Market segments**. No default list: a property that adds none sees no field.                             |
| Names      | Up to 50 characters, unique per property ignoring case.                                                                                |
| Remove     | A segment is turned off (Available), never deleted, so the bookings that have it keep it. A segment turned off cannot be picked again. |
| Optional   | A booking may have none. Channel, booking engine, portal and kiosk bookings have none.                                                 |
| Where set  | The booking form (all modes, shown only while the property has an active segment), and **More → Change market segment**.               |
| Closed     | It can change on a Completed or Cancelled reservation, because it changes no money.                                                    |
| Permission | Settings: `settings.manage`. On a booking: `reservations.manage`.                                                                      |

## Data model

```
market_segments  id, property_id (FK cascade), name varchar(50), active bool, timestamps
                 unique (property_id, lower(name))
reservations     + market_segment_id (FK market_segments, null on delete, nullable)
```

## Code

| Part                                                          | Role                                                                                                                                                                                                                    |
| ------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `MarketSegments` (Services/Reservations)                      | Settings list, the picker list (active, by name, one query), `isAvailable()` and `nameTaken()`.                                                                                                                         |
| `CreateMarketSegment`, `UpdateMarketSegment` (Actions)        | Add; rename or turn on and off. Log `market_segment.created` and `market_segment.updated` (changed fields).                                                                                                             |
| `MarketSegmentController`                                     | `settings.market-segments.index`, `.store`, `.update`. Nav item under Booking sources.                                                                                                                                  |
| `CreateReservation`                                           | Refuses `market_segment_not_available` and saves it. Logged on `reservation.created`.                                                                                                                                   |
| `UpdateReservationMarketSegment` (Action)                     | Set or clear. Same segment is not a change. Logs `reservation.market_segment_updated`; the Activity tab reads "Market segment: None → Leisure".                                                                         |
| `PUT /p/{property}/reservations/{reservation}/market-segment` | `reservations.market-segment.update`.                                                                                                                                                                                   |
| `RecordSelectField`, `ChangeMarketSegmentDialog`              | The picker (None clears) and the Dialog. The Dialog loads the list when it opens (`marketSegments` optional prop). The menu item shows while the property has any segment or the booking has one (`hasMarketSegments`). |

## Where it shows

- Reservation page, summary card: **Market segment** row when the booking has one.
- Booking performance: **Market segment** tab, with **No market segment**. See [Booking performance](../22-reports/02-guest-list-and-daily-report.md#5-booking-performance).
- Arrivals report: **Market segment** column (hidden by default). See [Arrivals columns](../22-reports/01-core-reports.md#arrivals-columns).

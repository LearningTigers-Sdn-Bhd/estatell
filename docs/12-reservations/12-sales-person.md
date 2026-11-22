# Sales person

## Purpose

Record the staff member who sold a booking, for example "Jane" from the sales team, apart from where it came from (the [booking source](11-booking-sources.md)) and who keyed it in (`created_by`). Tanini asked for it (requirement 2.5). Their old system put "Sales - Jane" in Business Source, so an OTA booking could not carry a sales person. Here it can.

**Status: built** (2026-11-21).

## Decisions

| Decision     | Rule                                                                                                                                                                                                                    |
| ------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Who          | An active member of the property (`User::activeMemberOf()`: an active membership there, staff given access from the group included; not a company portal user). A sales person needs a staff login. Decided 2026-11-21. |
| Optional     | Every booking may have none. Channel, booking engine, portal and kiosk bookings have none.                                                                                                                              |
| Where set    | The booking form (all modes: booking, walk-in, past, migrated and old stay), and **More → Change sales person** on the reservation page.                                                                                |
| Closed       | It can change on a Completed or Cancelled reservation too, because it changes no money.                                                                                                                                 |
| Deactivated  | A deactivated sales person stays on the bookings they sold. They cannot be picked again.                                                                                                                                |
| Deleted user | `nullOnDelete`: the booking keeps no sales person.                                                                                                                                                                      |
| Permission   | `reservations.manage`, like the booking form.                                                                                                                                                                           |

## Data model

```
reservations  + sales_person_id (FK users, null on delete, nullable)
```

## Code

| Part                                                        | Role                                                                                                                                                                                   |
| ----------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `SalesPeople` (Services/Reservations)                       | The picker list (one query) and `isAvailable()`.                                                                                                                                       |
| `CreateReservation`                                         | Refuses `sales_person_not_available` and saves it. Logged on `reservation.created`.                                                                                                    |
| `UpdateReservationSalesPerson` (Action)                     | Set or clear. Same person is not a change. Logs `reservation.sales_person_updated` with old and new ids. The Activity tab reads "Sales person: Jane → Mia" (`ReservationActivityLog`). |
| `PUT /p/{property}/reservations/{reservation}/sales-person` | `reservations.sales-person.update`.                                                                                                                                                    |
| `RecordSelectField`, `ChangeSalesPersonDialog`              | The picker (None clears) and the Dialog. The Dialog loads the list when it opens (`salesPeople` optional prop).                                                                        |

## Where it shows

- Reservation page, summary card: **Sales person** row ("None" when empty).
- Booking performance report: **Sales person** tab. Bookings without one share the **No sales person** row. See [Booking performance](../22-reports/02-guest-list-and-daily-report.md#5-booking-performance).
- Arrivals report: **Sales person** column (hidden by default). See [Arrivals columns](../22-reports/01-core-reports.md#arrivals-columns).

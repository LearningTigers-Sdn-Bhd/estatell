# Guest requests

## Purpose

Staff record what a guest asks for or complains about during a stay, hand it to someone, and close it. The module is a work queue for the front desk and housekeeping, and the data source for two dashboard KPIs: Complaint Resolution Time and Housekeeping Request Fulfillment Time.

Requests belong to the PMS. A concierge (a page, a chat or an AI) is only an intake channel. It sends requests into the same table later (see [Phases](#phases)).

## Scope

| In scope                                                                             | Out of scope                                                                                                                |
| ------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------- |
| Types: Housekeeping, Maintenance, Complaint, Other                                   | The concierge itself (phase 2 is only the API that receives its requests)                                                   |
| Staff entry, assign, start, resolve, cancel, reopen                                  | A guest-facing page that shows the request status                                                                           |
| A list page, a Requests tab on the reservation page, a tag on the housekeeping board | Vacant-room tasks and check-out turnovers (the [room status board](../19-housekeeping/01-room-status-board.md) covers them) |
| A To do row on the dashboard and two KPI tiles                                       | Targets per type, timers, escalation and push notifications                                                                 |
|                                                                                      | Note threads, photos, guest ratings, lost and found, wake-up calls                                                          |
|                                                                                      | Compensation. Staff use the folio discount and charge tools themselves                                                      |

## Data model

```
guest_requests
  id
  property_id             fk properties, not null
  reservation_unit_id     fk reservation_units, null    the stay; the reservation is reached through it
  room_id                 fk rooms, null                where the work happens
  type                    varchar(20)    GuestRequestType
  source                  varchar(20)    GuestRequestSource
  details                 varchar(1000), not null
  status                  varchar(20)    GuestRequestStatus, default 'open'
  assigned_to             fk users, null, nullOnDelete
  opened_at               timestamptz, not null
  opened_business_date    date, not null
  opened_by               fk users, null                null when the request came from the API
  resolved_at             timestamptz, null
  resolved_business_date  date, null
  resolved_by             fk users, null
  resolution_note         varchar(500), null            what was done, or why it was cancelled
  external_reference      varchar(100), null            the concierge's own id (phase 2)
  timestamps

CHECK  reservation_unit_id IS NOT NULL OR room_id IS NOT NULL
CHECK  (status = 'resolved') = (resolved_at IS NOT NULL)
CHECK  (resolved_at IS NULL) = (resolved_business_date IS NULL)
UNIQUE (property_id, external_reference) WHERE external_reference IS NOT NULL
INDEX  (property_id, status), (property_id, type, resolved_business_date),
       (reservation_unit_id), (room_id), (assigned_to)
```

- One table for every type. wastays had two near-identical tables (`complaint_requests`, `housekeeping_requests`).
- The business dates come from `CurrentBusinessDate`, or `PropertyToday` before business dates exist, like the board. A business date is not the calendar date near midnight. Storing it keeps the KPIs of a closed date fixed and the queries free of joins.
- `resolved_*` is set only for **Resolved**. A cancel keeps who and when in the activity log only, because Cancelled requests are left out of the KPIs.
- No jsonb and no `archived_at`. History lives in the activity log. The list filters by status and date instead of archiving.
- No copied labels: the room number, guest name and reservation reference are read through the foreign keys.

## Enums

| Enum                  | Cases                                                                                 | Notes                                                                   |
| --------------------- | ------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| `GuestRequestType`    | Housekeeping, Maintenance, Complaint, Other                                           | `label()`, `options()`, `tone()`, icon                                  |
| `GuestRequestSource`  | InPerson, Phone, Message, Concierge                                                   | Concierge is set only by the API. The staff form offers the other three |
| `GuestRequestStatus`  | Open, InProgress, Resolved, Cancelled                                                 | `label()`, `tone()`, `canTransitionTo()`                                |
| `GuestRequestRefusal` | `invalid_transition`, `stay_not_in_house`, `room_mismatch`, `resolved_on_closed_date` | Sentences in `lang/en.json` and `lang/ms.json`                          |

| From → To  | Open       | InProgress | Resolved | Cancelled |
| ---------- | ---------- | ---------- | -------- | --------- |
| Open       | –          | ✓          | ✓        | ✓         |
| InProgress | ✓          | –          | ✓        | ✓         |
| Resolved   | ✓ (reopen) | –          | –        | –         |
| Cancelled  | ✓ (reopen) | –          | –        | –         |

| Status     | Tone    |
| ---------- | ------- |
| Open       | Warning |
| InProgress | Info    |
| Resolved   | Success |
| Cancelled  | Neutral |

"Assigned" is the `assigned_to` column, not a status. wastays had 5 complaint statuses and 8 housekeeping statuses (`new`, `assigned`, `pending` and `no_task` overlapped); estatell has 4.

## Rules

| Rule                         | Detail                                                                                                                                                                      |
| ---------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Which stays accept a request | Housekeeping and Maintenance need a CheckedIn unit. Complaint and Other accept a CheckedIn or a CheckedOut unit (a guest who phones the next day). Else `stay_not_in_house` |
| Room                         | Defaults to the unit's room (or the bed's room) on the business date. A room the unit does not stay in during its stay is `room_mismatch`                                   |
| Request without a stay       | Allowed with a room only, for example a Complaint about a room heard from a visitor. The CHECK needs a unit or a room                                                       |
| Opened time                  | Staff entry uses now. There is no back-dating                                                                                                                               |
| Edit                         | Type, details and room can change while the request is Open or InProgress                                                                                                   |
| Assign                       | To a user of the property with `guest-requests.resolve`, or Unassign                                                                                                        |
| Resolve                      | Sets `resolved_at`, `resolved_business_date`, `resolved_by` and the optional note. It does not change the room status                                                       |
| Cancel                       | The note is required (for example "Duplicate" or "Guest no longer needs it")                                                                                                |
| Reopen a Resolved request    | Only while its `resolved_business_date` is the open business date, so a closed date's KPI never changes. Else `resolved_on_closed_date`; staff open a new request           |
| Reopen a Cancelled request   | Any time. It clears nothing, because a cancel stores no `resolved_*`                                                                                                        |
| Repeat click                 | A request already in the wanted status is not an error                                                                                                                      |
| Scope                        | Every query is scoped by `property_id`. A request, unit or room of another property is a 404                                                                                |

## Actions

All in `app/Actions/GuestRequests/`. Each logs activity on the request and returns a refusal result for an expected refusal (see the Actions rule).

| Action                | Does                                                                                                          | Logs                      |
| --------------------- | ------------------------------------------------------------------------------------------------------------- | ------------------------- |
| `OpenGuestRequest`    | Checks the stay and room, fills the room from the unit, sets `opened_at`, `opened_business_date`, `opened_by` | `guest_request.opened`    |
| `UpdateGuestRequest`  | Changes type, details or room while Open or InProgress                                                        | `guest_request.updated`   |
| `AssignGuestRequest`  | Sets or clears `assigned_to`                                                                                  | `guest_request.assigned`  |
| `StartGuestRequest`   | Open → InProgress                                                                                             | `guest_request.started`   |
| `ResolveGuestRequest` | → Resolved, with `resolved_*` and the optional note                                                           | `guest_request.resolved`  |
| `CancelGuestRequest`  | → Cancelled, with the required note                                                                           | `guest_request.cancelled` |
| `ReopenGuestRequest`  | → Open, clears `resolved_*`                                                                                   | `guest_request.reopened`  |

The module reads units and rooms through the Reservations and Rooms Services, not their models.

## Services

All in `app/Services/GuestRequests/`. They never write.

| Service                   | Reads                                                                                                                  |
| ------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `GuestRequestListBuilder` | Rows for the list page and the reservation tab, with the legal `moves` for the viewer                                  |
| `OpenGuestRequestCounts`  | Open and InProgress counts and the oldest `opened_at`, per room (the board) and per property (the dashboard To do row) |
| `GuestRequestMetrics`     | The two KPI medians for a period. The dashboard service calls it, so the Dashboard module never reads the model        |

## Permissions

| Permission               | Guards                                            | Owner | Manager | Front desk | Housekeeping |
| ------------------------ | ------------------------------------------------- | ----- | ------- | ---------- | ------------ |
| `guest-requests.view`    | The list page, the reservation tab, the board tag | ✓     | ✓       | ✓          | ✓            |
| `guest-requests.manage`  | Open, edit, assign, cancel, reopen                | ✓     | ✓       | ✓          | –            |
| `guest-requests.resolve` | Start and resolve                                 | ✓     | ✓       | ✓          | ✓            |

A syncing migration adds them (see [Roles and permissions](../03-staff/02-roles-and-permissions.md#seeding-and-syncing)). Front desk records a request; housekeeping does the work.

## Routes

| URL                                                 | Name                     | Permission                                    |
| --------------------------------------------------- | ------------------------ | --------------------------------------------- |
| `GET /p/{code}/requests`                            | `guest-requests.index`   | `guest-requests.view`                         |
| `POST /p/{code}/requests`                           | `guest-requests.store`   | `guest-requests.manage`                       |
| `PUT /p/{code}/requests/{guestRequest}`             | `guest-requests.update`  | `guest-requests.manage`                       |
| `PUT /p/{code}/requests/{guestRequest}/assign`      | `guest-requests.assign`  | `guest-requests.manage`                       |
| `POST /p/{code}/requests/{guestRequest}/start`      | `guest-requests.start`   | `guest-requests.resolve`                      |
| `POST /p/{code}/requests/{guestRequest}/resolve`    | `guest-requests.resolve` | `guest-requests.resolve`                      |
| `POST /p/{code}/requests/{guestRequest}/cancel`     | `guest-requests.cancel`  | `guest-requests.manage`                       |
| `POST /p/{code}/requests/{guestRequest}/reopen`     | `guest-requests.reopen`  | `guest-requests.manage`                       |
| `GET /p/{code}/reservations/{reservation}/requests` | `reservations.requests`  | `reservations.view` and `guest-requests.view` |

Routes live in `routes/guest-requests.php` with `scopeBindings()`. The reservation tab route lives with the other tab routes in `routes/reservations.php`. The in-house stays for the Sheet's picker load as an Inertia optional prop when the Sheet opens.

## UI

| Where                                                 | What                                                                                                                                                    |
| ----------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Front desk context > **Requests**, after Housekeeping | The work queue (Index archetype), a table                                                                                                               |
| Reservation page, **Requests** tab, before Activity   | See [Reservation page](../12-reservations/06-reservation-page.md#requests-tab)                                                                          |
| Housekeeping board                                    | A 🛎 tag in Flags and **New request** in the row ⋯ menu, see [Room status board](../19-housekeeping/01-room-status-board.md#guest-requests-on-the-board) |
| Front desk dashboard                                  | A **Requests** row in To do today                                                                                                                       |
| Dashboard Performance tab                             | The two KPI tiles                                                                                                                                       |

### List page

A table, not a kanban board. A small hotel has about 5 to 20 requests a day, a sorted table shows them at once, and the status badge works like the board's.

```
Requests                                                                  [ + New request ]
Status: (Open + In progress) v   Type v   Assigned v   Room v            Search guest, room…

 Age      Room  Guest           Type           Details                    Assigned  Status
 2 h 10m  204   Tan Wei Ming    Complaint      Aircon noisy at night      Aina      [● In progress ⌄]
 45 min   101   Siti Rahman     Housekeeping   Two extra towels           Siti      [● Open ⌄]
 12 min   310   John Carter     Maintenance    Shower drain blocked       –         [● Open ⌄]
```

- The default filter is Open and In progress. The default sort is oldest first. A column header overrides it.
- Filters: Status, Type, Assigned (including Unassigned), Room, and a search on guest name, room number and details.
- The status badge is the control. It lists only the legal moves for the viewer, sent by the server as `moves`. Resolve and Cancel open a small Dialog for the note. The badge updates at once (optimistic) and goes back with a toast on a refusal.
- A row click opens a Sheet: details, stay and room, source, who opened it, the timeline from the activity log, and the actions the viewer may take.
- On a narrow screen the table keeps Age, Room and Status, and the rest moves into the row detail.
- With no rows: "No open requests."

### New request Sheet

Opened from the list page, the reservation tab and the board row menu. The context fills the stay and the room.

```
New request
 Type     ( Housekeeping ) ( Maintenance ) ( Complaint ) ( Other )
 Stay     [ e.g. 204 · Tan Wei Ming · 12–15 Oct             v ]
 Source   ( In person ) ( Phone ) ( Message )
 Details  [ e.g. Two extra towels and a pillow                  ]
 Assign   [ e.g. Siti (optional)                             v ]
                                                   [ Cancel ] [ Save request ]
```

- The Stay picker lists the in-house units and today's departures, by room number and primary guest. A room-only request uses the **No stay, pick a room** option.
- The Sheet uses `FormOverlay`. A refusal shows on the matching field and the Sheet stays open.

### Dashboard To do row

| Order | Row          | When                                    | Button   |
| ----- | ------------ | --------------------------------------- | -------- |
| 5     | **Requests** | One or more Open or InProgress requests | **Open** |

The row reads "3 open requests · oldest 2 h 10 min" from `OpenGuestRequestCounts` and links to the list page. It needs `guest-requests.view`. The [dashboard](../18-front-desk/04-dashboard.md#to-do-today) doc gets the row when it is built.

## KPIs

| KPI                                       | Definition                                                                                                                         |
| ----------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| **Complaint Resolution Time**             | Median of `resolved_at − opened_at` over requests with type Complaint, status Resolved, and `resolved_business_date` in the period |
| **Housekeeping Request Fulfillment Time** | The same, with type Housekeeping                                                                                                   |

- **Median**, because one complaint left overnight would skew a mean. Postgres: `percentile_cont(0.5) WITHIN GROUP (ORDER BY resolved_at - opened_at)`.
- **Grouped by resolved business date**, so a closed date's figure never changes. The reopen rule protects this.
- **Clock time**, not working hours. A request at 23:00 resolved at 08:00 counts 9 h, as the guest felt it.
- **Cancelled** requests are left out.
- **Reopened** requests run from the first `opened_at` to the last `resolved_at`.
- **Open requests** have no duration, so they are not in the median. The tile shows a second line, "N still open · oldest 5 h 10 min": requests opened in the period that are still Open or InProgress.
- **Display**: under 1 h as "42 min", then "3 h 15 min", over 48 h as "2.4 days". The tile shows the sample size ("from 18 resolved"). With no resolved requests it reads "No resolved complaints", never 0.

`GuestRequestMetrics` returns, for each of the two types: the median in seconds (or null), the resolved count, the still-open count and the oldest open `opened_at`.

## Phases

| Phase | Contents                                                                                                                                                                                                                                                                                                                                                                           |
| ----- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1     | Table, enums, Actions, Services, permissions, the list page, the Sheet, the reservation tab, the dashboard To do row and `GuestRequestMetrics`. Staff entry only                                                                                                                                                                                                                   |
| 1b    | The housekeeping board tag, popover and row menu item                                                                                                                                                                                                                                                                                                                              |
| 2     | A concierge API. `ReceiveConciergeRequest` upserts on `(property_id, external_reference)` and calls `OpenGuestRequest` with `source = Concierge` and `opened_by = null`. A missing reference is refused. A `GuestRequestResolved` event lets the concierge be told. The schema does not change. How the API signs in (for example Sanctum) is a dependency decision for that phase |
| Later | Notify the assigned staff, photos (medialibrary), targets per type                                                                                                                                                                                                                                                                                                                 |

## Lessons from wastays

- Two tables with the same shape, and a `StatusUpdater` that took `kind: "complaint"` as a string. estatell has one table and a `type` enum.
- `internal_notes` and `metadata` were jsonb. estatell has `resolution_note` and the activity log.
- `room_number` was copied next to `room_id`, and `hotel_id` was optional with a fallback through the booking. estatell has a required `property_id` and foreign keys only.
- `work_context` mixed guest requests with vacant-room tasks and turnovers. estatell keeps room work on the room status board.
- `Housekeeping::SyncRequestEvent` overwrote the booking's latest request when the payload had no `external_id`. `ReceiveConciergeRequest` refuses a missing reference.

# Admin Channels tab

## Purpose

The one place where the estatell team syncs a property to Channex, connects and maps OTAs, and fixes problems.

## Where

- A **Channels** tab on the admin property page, after OTA lists (see [Admin properties](../01-platform/05-admin-properties.md#show)). Shown only when `ChannelManager` is on.
- The connect steps are their own page, `pages/admin/properties/channels/connect.tsx`.

## Tab states

### Not synced

```
┌─ Overview · Staff · Features · OTA lists · [Channels] ──────────────────────────┐
│ ┌─ Channex ───────────────────────────────────────────────────────────────────┐ │
│ │ ○ Not synced                                                                │ │
│ │ Send this property's room types and rate plans to Channex.                  │ │
│ │                                                    [ Preview sync ]         │ │
│ └─────────────────────────────────────────────────────────────────────────────┘ │
│ ┌─ OTAs ──────────────────────────────────────────────────────────────────────┐ │
│ │ Sync to Channex first.                                                      │ │
│ └─────────────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────────┘
```

### Preview sync (Dialog)

```
┌─ Sync to Channex ───────────────────────────────────────────────────────────────┐
│ Room type      Rate plan            Sell mode         Result                    │
│ Deluxe King    Standard             Per room          ✓ Full                    │
│ Family Suite   Standard             Per guest (1–4)   ✓ Full                    │
│ Family Suite   3-night package      Flat package      ~ Flattened               │
│                                     min = max stay 3, closed to arrival Mon–Thu │
│ Garden Room    Day use · Hourly     Day use           ✕ Day use plans are sold  │
│                                                         at the front desk only. │
│ 6-Bed Dorm     Bed rate             Per bed           ✕ Dorm beds are not on    │
│                                                         channels yet.           │
│ 3 room types · 4 rate plans to create · 2 skipped                               │
│ After the sync, the next 500 days of availability, rates and restrictions are   │
│ sent.                                       [ Cancel ]  [ Sync to Channex ]     │
└─────────────────────────────────────────────────────────────────────────────────┘
```

Results and reasons come from `ChannelCapability` (see [Mappings and capability](02-mappings-and-capability.md#service)).

### Built with the structure sync

The tab has three parts so far: the Channex card (status, the last sync, the last error, **Preview sync** or **Sync again**), the preview Dialog above (the sync button is off while the preview is blocked), and **On Channex**, which lists the room types and rate plans Channex has with a **Remove** button on each (an AlertDialog). The Channex card also shows when changes were last sent and the last full refresh, the paused line ("Paused until 09:12. 6 changes waiting, none lost.") while `paused_until` is in the future, and a **Full refresh (500 days)** button (see [ARI sync](04-ari-sync.md#full-sync)). The OTAs card says "Sync to Channex first." until a property is synced; connecting OTAs and **Pause sync** come later. The tab and its routes return 404 while `ChannelManager` is off.

### Built with booking ingestion

Below **On Channex** the tab shows **Alerts** and **Recent bookings from channels**. Alerts lists the revisions that were not saved (**Retry**) and the open alert webhooks (**Close**). Recent bookings lists the last 20 revisions with the Channex booking code, channel, kind, guest, stay, room count and the reservation reference. Both are read-only apart from the buttons. **Fix mapping** comes with the OTA mapping screen. The routes are `admin.properties.channels.revisions.retry` and `admin.properties.channels.alerts.resolve` (POST, `support.2fa`).

### Sync in the background

**Sync to Channex** (and **Sync again**) queue the sync and close the Dialog. The Channex card then shows a progress bar and the line "Syncing to Channex: 12 of 26 steps. You can leave this page.", the sync buttons are off, and one toast keeps the same count until the sync ends. The toast then becomes "Synced to Channex" or "Sync to Channex failed" with the reason. If the admin was not on the page when it ended, the card shows the result (status and last error) and no toast. See [Background sync](02-mappings-and-capability.md#payloads).

### Automatic sync and removals

When staff change a room type, a rate plan or the room types a plan sells, the card shows "Changes are waiting to sync" for about 2 minutes, then the sync runs by itself with the same progress and toast (see [Automatic sync](02-mappings-and-capability.md#automatic-sync-after-staff-change-something)). When staff archive an item that is on Channex, it is removed there first (see the [archive guard](02-mappings-and-capability.md#archive-guard)). Two kinds of alert come from this: **Structure sync failed** and **Removal blocked** (an OTA still uses the item). Both have **Close**. The **Remove** buttons under **On Channex** stay for an item that could not be cleared.

### Live

```
┌─ Overview · Staff · Features · OTA lists · [Channels] ──────────────────────────┐
│ ┌─ Channex ───────────────────────────────────────────────────────────────────┐ │
│ │ ● Synced · Structure today 09:12 · Changes sent 12 s ago                    │ │
│ │ Full refresh last night 03:04          [ Sync again ] [ ⋯ ]                 │ │
│ │ 3 room types · 4 rate plans · 2 skipped (view)                              │ │
│ │                                        ⋯ Pause sync · Full refresh (500 d)  │ │
│ │ ◐ Paused 1 min after a rate limit (429). 6 changes waiting, none lost.      │ │
│ └─────────────────────────────────────────────────────────────────────────────┘ │
│ ┌─ OTAs ──────────────────────────────────────────────────────── [ + Connect ]┐ │
│ │ Channel      Hotel ID   Status                Bookings (30 d)  Last error  │ │
│ │ Agoda        1234567    ● Active              14               —           │ │
│ │                                     [ Mapping ] [ ⋯ ]                      │ │
│ │ Booking.com  5868189    ◐ Help requested · Sign-in code · 2 h ago          │ │
│ │                                     [ Continue ] [ ⋯ ]                     │ │
│ │ Hostelworld  —          ○ Not connected       —                —           │ │
│ │                                     [ Connect ]                            │ │
│ │ Airbnb       —          Connect in Channex by hand (coming later)          │ │
│ │ Traveloka    —          No Channex adapter. Connect in Channex by hand.    │ │
│ │  ⋯ = Ask owner for help · Mark help resolved · Pause · Disconnect          │ │
│ │      · Import future bookings (Booking.com, Expedia)                       │ │
│ └─────────────────────────────────────────────────────────────────────────────┘ │
│ ┌─ Alerts ─────────────────────────────────────────────────── 3 open ────────┐ │
│ │ ✕ Agoda · Booking not saved · AG-88213 · unmapped rate     [ Fix mapping ] │ │
│ │   Not acknowledged to Channex until it is saved.            [ Retry ]      │ │
│ │ ⚠ Channex · Not acknowledged booking · AG-88213 · 10:56                    │ │
│ │ ✕ ARI · Rate error · Deluxe King 12–14 Nov · 08:03           [ Retry ]     │ │
│ └─────────────────────────────────────────────────────────────────────────────┘ │
│ ┌─ Recent bookings from channels ─────────────────────────────────────────────┐ │
│ │ AG-88214  Agoda    New        Tan Wei Ming  12–14 Nov  2 rooms  ✓ R-1043   │ │
│ │ AG-88213  Agoda    New        —             15 Nov     1 room   ✕ not saved│ │
│ └─────────────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────────┘
```

- The OTAs list shows every active `ota_channels` row that has a connection or an OTA lists row, plus **+ Connect** for any other channel with a Channex code.
- The paused line shows only while `paused_until` is in the future or changes keep failing.

## Connect, step 1: connection

```
┌─ Connect Booking.com ──────────────────── 1 Connect · 2 Map · 3 Activate ─┬─ From OTA lists ─────────────────┐
│ Channel            [ Booking.com                     ▾ ]                  │ Booking.com                      │
│ Hotel ID           [ e.g. 5868189                      ]                  │ Property ID   —  (not given)     │
│                    Find it at the top of the Booking.com extranet,        │ Username      seripagi.bdc       │
│                    next to the property name.                             │ Password      On file [Reveal]   │
│ Send emails        [  ○──] Off                                            │ Status        Pending            │
│ Property email     [ e.g. reservations@seripagi.my     ]  (when on)       │ ──────────────────────────────── │
│                                                                           │ Market manager                   │
│ Before testing: sign in to the Booking.com extranet with the login in     │ Aina Rahman · +60 12-345 6789    │
│ the rail, and choose Channex as the connectivity provider.                │ ──────────────────────────────── │
│                                       [ Test connection ]                 │ Owner                            │
│ ✓ Booking.com sees Channex as the provider for hotel 5868189.             │ Encik Hafiz · +60 19-876 5432    │
│   ── or ──                                                                │ [ WhatsApp ]  hafiz@seripagi.my  │
│ ✕ Booking.com refused the connection. Choose Channex as the provider in   │ ──────────────────────────────── │
│   the extranet, check the hotel ID, then test again.                      │ ⚠ Hotel ID missing. Find it in   │
│                                                                           │ the extranet after signing in.   │
│                                                                           │ [ Ask owner for help ]           │
│                                              [ Cancel ]  [ Next: map ]    │ [ Open OTA lists tab ]           │
└───────────────────────────────────────────────────────────────────────────┴──────────────────────────────────┘
```

- The OTA comes from the Connect button on the OTAs card, so the form has no Channel select.
- Hotel ID is required. **Save connection** tests the settings again and creates the Channex channel as a `Draft`, then goes back to the tab. The map commit turns it into **Next: map**.
- A property that is not on Channex, an OTA with no adapter and Airbnb go back to the tab with the reason.

### Fields come from the adapter

`GET /channels/adapter?code={channex_code}` returns `params`. The form draws one input per param:

| Param type | Input                                                                |
| ---------- | -------------------------------------------------------------------- |
| `string`   | Text input with an `e.g.` placeholder                                |
| `boolean`  | Switch                                                               |
| `select`   | Select, Channex default preselected                                  |
| `password` | Password input                                                       |
| `hidden`   | Not shown                                                            |
| `rules`    | Show or hide, for example Property email only when Send emails is on |

- `ChannelFieldCopy` holds our label, hint and placeholder per channel code and param. Channex's title is the fallback.
- Recommended defaults for Agoda tax and for Hostelworld and Expedia booking amount are set from the [staging checks](06-staging-verification.md#open-checks), because they decide the folio amount.

### Reference rail

| OTA lists row       | Hotel ID field                   | Rail shows                                                          |
| ------------------- | -------------------------------- | ------------------------------------------------------------------- |
| Has Property ID     | Pre-filled, editable             | Row details, owner contact                                          |
| No Property ID      | Empty, with placeholder and hint | "⚠ Hotel ID missing", login, contact, Ask owner for help            |
| No row              | Empty                            | "Not in OTA lists", contact, Ask owner for help, Open OTA lists tab |
| Typed value differs | As typed                         | "Differs from OTA lists (5868180)", information only                |

- Nothing typed here writes back to OTA lists.
- **Reveal** is the same POST as on the OTA lists tab (`support.2fa`, logged).
- Owner contact is the property's first active Owner and the property phone. **WhatsApp** opens `wa.me/{phone}`.
- Below `lg` the rail drops under the form.

## Connect, step 2: map

```
┌─ Connect Agoda ─────────────────────────── 1 Connect · 2 Map · 3 Activate ─┐
│ Rooms and rates from Agoda, fetched just now.              [ Fetch again ] │
│ estatell room · rate            Occ   Agoda room · rate            Primary │
│ Deluxe King · Standard           2 ─▶ [ Deluxe Double (5521) · BAR ▾ ] ◉  │
│ Deluxe King · Standard           1 ─▶ [ Deluxe Double (5521) · BAR ▾ ] ○  │
│ Family Suite · Standard          4 ─▶ [ Family Room (5530) · BAR   ▾ ] ◉  │
│ Family Suite · Standard          2 ─▶ [ Not mapped                 ▾ ] ○  │
│ Not on channels: Garden Room · Day use, 6-Bed Dorm · Bed rate              │
│ ⚠ Agoda has 1 rate with no match: Deluxe Double · Non-refundable.          │
│   Unmapped Agoda rates cause booking errors. Map it, or ask the owner to   │
│   remove it.                                      [ Ask owner for help ]   │
│ 6 of 7 mapped                               [ Back ]  [ Save mapping ]     │
└────────────────────────────────────────────────────────────────────────────┘
```

- Rows: one per Channex rate plan and occupancy. Columns come from `rate_params`. Hostelworld shows **Type** instead of **Primary**.
- Selects are pre-filled by matching names. The admin confirms every row.

## Connect, step 3: activate

```
┌─ Activate Agoda? ─────────────────────────────────────────────┐
│ Agoda starts receiving availability, rates and restrictions   │
│ for the next 500 days, and bookings start arriving here.      │
│ Check that Agoda's own rates and availability are closed or   │
│ correct. Channex overwrites them.                             │
│                                  [ Not now ]  [ Activate ]    │
└───────────────────────────────────────────────────────────────┘
```

## Ask owner for help

```
┌─ Ask owner for help · Booking.com ───────────────────────────┐
│ Reason   [ Sign-in code needed                          ▾ ]  │
│ Message  [ e.g. We're signing in to Booking.com at 3 pm.  ]  │
│          [ Please send us the code you receive.           ]  │
│                                     [ Cancel ]  [ Send ]     │
└──────────────────────────────────────────────────────────────┘
```

- Reasons come from `ChannelHelpReason`. Message is required for Other.
- `RequestOwnerHelp` stores the request and sends `ChannelHelpRequested` to the owner. One open request per connection; a new one replaces the message.
- **Mark help resolved** (`ResolveOwnerHelp`) closes it. Both are logged.

## Routes

All are `auth`, `can:admin`, return 404 when `ChannelManager` is off, and need `support.2fa` for writes.

| Method and URL                                                        | Name                                             |
| --------------------------------------------------------------------- | ------------------------------------------------ |
| `GET /admin/properties/{code}/channels`                               | `admin.properties.channels`                      |
| `POST /admin/properties/{code}/channels/preview`                      | `admin.properties.channels.preview` (JSON)       |
| `POST /admin/properties/{code}/channels/sync`                         | `admin.properties.channels.sync`                 |
| `POST /admin/properties/{code}/channels/full-refresh`                 | `admin.properties.channels.full-refresh`         |
| `PUT /admin/properties/{code}/channels/paused`                        | `admin.properties.channels.paused`               |
| `GET /admin/properties/{code}/channels/connect/{otaChannel}`          | `admin.properties.channels.connect`              |
| `POST /admin/properties/{code}/channels/connect/{otaChannel}/test`    | `admin.properties.channels.test` (JSON)          |
| `POST /admin/properties/{code}/channels/connect/{otaChannel}/details` | `admin.properties.channels.details` (JSON)       |
| `POST /admin/properties/{code}/channels/connections`                  | `admin.properties.channels.connections.store`    |
| `PUT /admin/properties/{code}/channels/connections/{connection}`      | `admin.properties.channels.connections.update`   |
| `POST …/connections/{connection}/activate`                            | `admin.properties.channels.connections.activate` |
| `PUT …/connections/{connection}/paused`                               | `admin.properties.channels.connections.paused`   |
| `DELETE …/connections/{connection}`                                   | `admin.properties.channels.connections.destroy`  |
| `POST …/connections/{connection}/import-bookings`                     | `admin.properties.channels.connections.import`   |
| `POST …/connections/{connection}/help`                                | `admin.properties.channels.help.store`           |
| `PUT …/connections/{connection}/help/resolved`                        | `admin.properties.channels.help.resolve`         |
| `POST /admin/properties/{code}/channels/revisions/{revision}/retry`   | `admin.properties.channels.revisions.retry`      |
| `POST /admin/properties/{code}/channels/changes/retry`                | `admin.properties.channels.changes.retry`        |

## Logging

Sync, full refresh, pause, connect, mapping save, activate, disconnect, import, help request and help resolved each write an activity log entry on the property.

# Owner Channels page

## Purpose

Show owners and managers what is on their channels, without letting them change anything. The estatell team does the setup.

## Where

- **Settings → Channels**, `GET /p/{code}/channels` (`channels.index`), `pages/channels/index.tsx`.
- Middleware: `live`, `ChannelManager` on (404 when off), `can:channel.view`.
- No dashboard card, flag on or off.

## Layout

```
┌─ Settings › Channels ───────────────────────────────────────────────────────────┐
│ The estatell team connects and looks after your channels. Prices, availability  │
│ and restrictions you set here are sent to them automatically.                   │
│                                                                                 │
│ ┌─ The team needs your help ──────────────────────── only when raised ────────┐ │
│ │ Booking.com · Sign-in code needed · 2 h ago                                 │ │
│ │ "We're signing in to Booking.com at 3 pm. Please send us the code you       │ │
│ │  receive."                                    [ WhatsApp the team ]         │ │
│ └─────────────────────────────────────────────────────────────────────────────┘ │
│                                                                                 │
│ Channel      Status                    Since      Bookings (30 d)               │
│ Agoda        ● Live                    12 Oct     14                            │
│ Booking.com  ◐ Being connected         —          —                             │
│ Hostelworld  ○ Waiting for the team    —          —        (in your OTA lists)  │
│                                                                                 │
│ ┌─ What is on channels ───────────────────────────────────────────────────────┐ │
│ │ ✓ Deluxe King · Standard, Breakfast included                                │ │
│ │ ✓ Family Suite · Standard, 3-night package                                  │ │
│ │ ✕ Garden Room · Day use     Day use plans are sold at the front desk only.  │ │
│ │ ✕ 6-Bed Dorm · Bed rate     Dorm beds are not on channels yet.              │ │
│ └─────────────────────────────────────────────────────────────────────────────┘ │
│                                                                                 │
│ ┌─ Recent channel bookings ───────────────────────────────────────────────────┐ │
│ │ AG-88214  Agoda    New        Tan Wei Ming  12–14 Nov   R-1043  [ Open ]    │ │
│ │ BC-55120  Booking  Cancelled  Siti Aminah   3–5 Nov     R-1029  [ Open ]    │ │
│ └─────────────────────────────────────────────────────────────────────────────┘ │
│ Questions? Contact the estatell team.                  [ WhatsApp ] [ Email ]   │
└─────────────────────────────────────────────────────────────────────────────────┘
```

- **The team needs your help** shows one box per open `channel_help_requests` row. There is no reply form: the owner answers by WhatsApp or email. The team marks it resolved.
- **What is on channels** uses the same `ChannelCapability` reasons as the admin preview.
- **Recent channel bookings** lists the last 10 saved channel bookings. Unsaved ones are not shown.
- The team's WhatsApp and email come from platform settings. To decide when built: the setting names.

## Statuses

`ChannelOwnerStatus`, worked out from the admin state:

| Admin state                                    | Owner sees                      |
| ---------------------------------------------- | ------------------------------- |
| OTA lists row, no connection yet               | ○ Waiting for the team          |
| Connection Draft or Mapped                     | ◐ Being connected               |
| Help request open                              | ◐ Being connected, plus the box |
| Active                                         | ● Live                          |
| Paused or Error, on the connection or property | ◐ Paused by the team            |

## Other owner touchpoints

| Where                       | What                                                                                      |
| --------------------------- | ----------------------------------------------------------------------------------------- |
| Settings → OTA lists        | New intro, plus a read-only **Channel** column with the same status (flag on only)        |
| Rate calendar and bulk edit | One line: "Changes go to Agoda and Booking.com within a minute." Lists the Live channels. |
| Reservations                | OTA booking source badge and `external_reference`                                         |
| Archive room type or plan   | The [archive guard](02-mappings-and-capability.md#archive-guard) message                  |
| Notifications               | `ChannelBookingReceived`, `ChannelBookingCancelled`, `ChannelHelpRequested`               |

## Never shown to owners

Channex (the name, IDs and settings), hotel ID fields, the mapping table, sync errors and pauses, task IDs, unsaved bookings, alerts, pause, disconnect, full refresh, and Reveal.

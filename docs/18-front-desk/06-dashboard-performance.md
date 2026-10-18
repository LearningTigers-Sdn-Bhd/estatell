# Dashboard: Performance tab

## Purpose

How the business is doing over a period: occupancy, ADR, RevPAR, channel mix and lead time. It is for the owner and the manager. The [Today tab](04-dashboard.md) stays the front desk's place to act and does not change.

`GET /p/{code}/dashboard?tab=performance` uses the same `DashboardController::show` and `pages/dashboard.tsx`. There is no new route.

## Tabs

```
Dashboard · Sandbay Resort                          Business date 18 Oct 2026
                                                    [ Today ] [● Performance]
```

| Tab             | Shows                                    | Permission              |
| --------------- | ---------------------------------------- | ----------------------- |
| **Today**       | The existing dashboard, unchanged        | Each cell keeps its own |
| **Performance** | KPI tiles, Trend, Channel mix, Lead time | `reports.view`          |

- A user without `reports.view` sees no tab bar, only the Today content. A `?tab=performance` visit from that user shows Today.
- The tab lives only in the URL: each tab is a link that sets `tab`. Today is the default, and a bad value shows Today. The browser does not remember the last tab; an owner who wants Performance first bookmarks it.
- `DashboardTab` (Today, Performance) holds the values.
- Above the Performance content, the **night audit alert** still shows, because a stuck audit changes the figures. Quick actions and the **You're live** card show on Today only. The page header with **Run night audit** shows on both tabs.
- Only the open tab's props are sent. Today's tiles, To do and housekeeping are not loaded on Performance, and the reverse.

## Layout

```
Period [Last 7 days] [Last 30 days] [●This month] [Last month] [12 months] [Month ⌄]
1 Oct – 18 Oct 2026 · compared with 1 Sep – 18 Sep 2026

┌ Occupancy ─────┐┌ ADR ───────────┐┌ RevPAR ────────┐┌ Lead time ───────┐
│ 68%            ││ MYR 285        ││ MYR 194        ││ 21 days          │
│ ▲ 4 pt         ││ ▲ 3%           ││ ▲ 9%           ││ ▼ 2 days         │
└────────────────┘└────────────────┘└────────────────┘└──────────────────┘
┌ Trend ────────────── [●Occupancy|ADR|RevPAR] ┐┌ Channel mix  [●Source|Type]┐
│ 68%  ▲ 4 pt  vs 1 Sep – 18 Sep 2026          ││ ████████████████░░░░░░░░░░ │
│100%│       ▆      ▇                          ││ ● Booking.com   42%  257 n │
│ 75%│  ▅ ▆ █ ▇ ▅ ▆ █ ▇ ▆ ▅ ▆ ▇                ││ ● Phone         15%   92 n │
│ 50%│- █-█-█-█-█-█-█-█-█-█-█-█- - prev        ││ ● WhatsApp      14%   86 n │
│ 25%│  █ █ █ █ █ █ █ █ █ █ █ █                ││ ● Company / TA  11%   67 n ›│
│    └──────────────────────────────────       ││ ● Agoda          9%   55 n │
│     1 Oct          9 Oct          18 Oct     ││ ● Walk-in        9%   55 n │
│                                              │└────────────────────────────┘
│                                              │┌ How far ahead guests book ─┐
│                                              ││ Arrivals 1–18 Oct ·        │
│                                              ││ walk-ins not counted       │
│                                              ││ Same day  ███░░░░░░  12%   │
│                                              ││ 1–7 days  ███████░░  31%   │
│                                              ││ 8–30 days █████████  38%   │
│                                              ││ 31+ days  ████░░░░░  19%   │
└──────────────────────────────────────────────┘└────────────────────────────┘
```

| Width       | Layout                                                                                                                                            |
| ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| `xl` 1280px | Four tiles in one row. Trend takes two of three columns; Channel mix and Lead time stack in the third. This row fills the rest of the page height |
| 768–1279px  | Tiles 2×2. Trend, Channel mix and Lead time stack at full width                                                                                   |
| Below 768px | Tiles 2×2. Cards stack. The period presets scroll sideways in one row                                                                             |

- Tiles follow the Today stat tile style: a normal card, a soft primary icon chip (`primary/5`, like the month tile), strong figure. No left accents.
- Every tile has a line under the figure: the comparison, or "Nothing to compare with" when the compared range has no figures.
- The Trend and Channel mix switches show an icon before each label: Occupancy `BedDouble`, ADR `Coins`, RevPAR `TrendingUp` (the KPI tile icons), Source `Globe`, Type `Shapes`.
- Each card heading has one muted 20px Lucide icon: Trend `ChartLine`, Channel mix `ChartBarStacked`, Lead time `Hourglass`. The Lead time card is titled "How far ahead guests book", with the arrival range and "walk-ins not counted" as its description.
- A muted **Period** label sits left of the presets. The period presets are one outline toggle group at the normal size, like the Trend metric switch. Choosing one visits its URL. A picked month leaves no preset pressed. The month picker is hidden while there is no whole past month to pick.
- The comparison line uses a soft tint: up is `primary`, down is `warning-text`. For lead time neither direction is good or bad, so it stays muted.
- The trend chart uses `components/ui/chart.tsx`: `chart-3` bars with rounded tops for the current period, one per day (or month for 12 months), and a thin dashed muted line for the compared one. Bars, not a line, because the figures are separate days: no curve invents values between them.
- Above the chart, a headline shows the chosen metric's period figure, its change chip and the compared range. It uses the same values as the tiles.
- The occupancy axis rounds up to the next 25%, at least 0–25%. ADR and RevPAR ticks use compact money ("MYR 300", "MYR 1.2K").
- A day with no figure has no bar. The tooltip names the reason: "No night audit", or for ADR "No rooms sold". Under the chart, muted notes show only when they apply: "No night audit figures before 7 Oct" and "Days without a bar had no rooms sold".
- A solid zero baseline (`foreground` at 30%) sits under the bars. Revenue counts on its posting date (see [Statistics](../20-night-audit/05-statistics.md)), so a correction can take a day's ADR or RevPAR below zero. That bar hangs from the baseline in `warning`, rounded at the bottom; the figure is never clamped, so the chart matches the Occupancy report. Its tooltip says "Corrections reversed earlier nights", and a note under the chart explains bars below zero when the range has one.
- The headline shows "vs {compared range}" only beside a real change chip. With nothing to compare, the chip reads "Nothing to compare with" alone.
- The tooltip shows the date, then This period and Compared with their color marks: whole percent for occupancy, full money for ADR and RevPAR.
- Channel mix colors come from each source's badge `SwatchColor` (see [Booking sources](../12-reservations/11-booking-sources.md#badge)). By type, the four types use fixed chart tokens.

## Period

| Preset                   | Range                                               | Compared with                                | Trend by |
| ------------------------ | --------------------------------------------------- | -------------------------------------------- | -------- |
| Last 7 days              | 7 days ending on the business date                  | The 7 days before                            | Day      |
| Last 30 days             | 30 days ending on the business date                 | The 30 days before                           | Day      |
| **This month** (default) | The 1st to the business date                        | The 1st to the same day number of last month | Day      |
| Last month               | The whole previous month                            | The month before                             | Day      |
| Last 12 months           | The 11 whole months before, plus this month to date | The same range one year earlier              | Month    |
| Month picker             | Any whole past month                                | The month before                             | Day      |

- Every range ends on or before the property **business date** (`CurrentBusinessDate`), never the calendar date. A future month is not offered.
- When the day number does not exist in last month (31 Oct → September), the comparison ends on the last day of that month.
- URL: `?tab=performance&period=7d|30d|month|last-month|12m`, or `&month=2026-09` for the picker. A bad value falls back to This month. `PerformancePreset` holds the five presets.
- **Last 12 months** compares with the same range one year earlier (for 18 Oct 2026: 1 Nov 2024 – 18 Oct 2025), so a partial current month is never set against a whole one.
- **Month picker** offers whole months from the **earliest month with data** to last month. The earliest date is the earlier of the first `daily_statistics.business_date` and the first `unit_nights.date` of a live unit. This also covers stays imported from a previous system later.
- The line under the presets always states both ranges in words, so "This month" is never a guess.
- The comparison is hidden when the previous range has no data (a new property).

## KPIs

| KPI             | Figure                                                       | Source                                                                                     |
| --------------- | ------------------------------------------------------------ | ------------------------------------------------------------------------------------------ |
| **Occupancy**   | Σ rooms sold ÷ Σ rooms available                             | `daily_statistics` for closed dates; `StatisticsCalculator::forDate()` for the open date   |
| **ADR**         | Σ room revenue ÷ Σ rooms sold                                | Same                                                                                       |
| **RevPAR**      | Σ room revenue ÷ Σ rooms available                           | Same                                                                                       |
| **Lead time**   | Average whole days from booking to arrival, and four buckets | `reservations.created_at` and `reservation_units.arrival_date`                             |
| **Channel mix** | Share of room nights by booking source, or by source type    | `unit_nights` → `reservation_units` → `reservations.booking_source_id` → `booking_sources` |

- **Occupancy, ADR and RevPAR** use the same sums as the Occupancy report totals (`HasTotals`), so the tab and the report never disagree for the same dates. They are overnight only; day use stays out, as in the report.
- The comparison shows occupancy in points ("▲ 4 pt"), ADR and RevPAR in percent, and lead time in days. Deltas are worked out from unrounded values, then rounded for display.
- The occupancy, ADR and RevPAR comparison is hidden when the previous range has no available rooms.

### Lead time

- Counts live units (Confirmed, CheckedIn, CheckedOut) with `arrival_date` in the range, overnight and day use.
- Days = `arrival_date` − the booking's `created_at` converted to the property time zone, as a date. Same day is 0. A negative result (a time zone edge) counts as 0.
- **Walk-ins are left out** (units whose booking source has type Walk-in). They are always 0 and would pull the average down. They still count in Channel mix.
- Buckets: Same day (0), 1–7 days, 8–30 days, 31+ days, as a share of the counted units.
- The tile shows the average rounded to whole days.

### Channel mix

- Counts room nights by stay date (`unit_nights.date` in the range) of live units. Cancelled and no-show units stay out.
- **Source** view: one row per booking source, by its name and badge, largest first. **Type** view: Direct, OTA, Company / Travel agent, Walk-in.
- Each row shows its share and room nights. The tooltip adds the room total (`SUM(unit_nights.amount)`).
- Sources with no nights in the range are not listed. Shares are rounded so the list adds up to 100%.
- **›** on a Company / Travel agent row opens a popover by company: name and room nights, largest first. Bookings with a company but a Direct or OTA source stay in their own source.
- A manual Booking.com booking and one from the channel manager share one source row, so the mix is continuous before and after the channel connection.

## Empty and loading

- With no stays in the range, each figure shows "–" with "No stays in this period", never 0%. Lead time reads "No arrivals in this period".
- When the range has stays but no night audit figures (dates before go-live, for example imported stays), Occupancy, ADR and RevPAR show "–" with "No night audit figures for this period". Channel mix and Lead time still show their figures.
- The whole tab is one **deferred prop group**. The first load shows a skeleton in the shape of the tiles and cards.
- Changing the period reloads only the period and `performance` props. Inertia v3 `<Deferred>` shows no fallback on a partial reload, so the cards dim (its `reloading` state) until the new figures arrive.

## Build shape

| Part       | What                                                                                                                                                                                     |
| ---------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Service    | `app/Services/Dashboard/PerformanceSummary.php`: one period and its comparison, in grouped queries. The query count stays fixed for 7 days and 12 months                                 |
| Period     | `PerformancePeriod` (value object in the same folder): parses the URL value and builds the range and comparison from the business date                                                   |
| Data       | `PerformanceSummaryData`, `PerformancePeriodData` and small nested Data classes (KPI, trend point, channel mix row, lead time) in `app/Data/Dashboard/`, with generated TypeScript types |
| Enums      | `DashboardTab` and `PerformancePreset` in `app/Enums/`                                                                                                                                   |
| Controller | `DashboardController::show` reads `tab` and the period and sends the `performance` deferred prop only on that tab                                                                        |
| Frontend   | The tab bar in `pages/dashboard.tsx`; the cards in `components/pms/dashboard/performance/`                                                                                               |

## Later

| Item                                                                      | Waits for                                                                                                               |
| ------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| Complaint Resolution Time and Housekeeping Request Fulfillment Time tiles | [Guest requests](../25-guest-requests/01-guest-requests.md#kpis) and `GuestRequestMetrics`                              |
| Same month last year                                                      | A year of data                                                                                                          |
| Next 30 days on the books                                                 | A decision on forward-looking figures                                                                                   |
| Lead time and occupancy for stays imported from a previous system         | A booking import with the original booking date (`booked_at`) and, optionally, daily history as `daily_statistics` rows |

## Tests

- No tab bar and no `performance` prop without `reports.view`. `?tab=performance` from that user shows Today.
- Occupancy, ADR and RevPAR equal the Occupancy report totals for the same range, with closed dates and the open date.
- Each preset builds the right range and comparison from the business date, including 31 Oct → 30 Sep and 12 months → one year earlier. The picker refuses months before the earliest data and after last month.
- Lead time leaves out walk-ins and cancelled units, uses the property time zone for `created_at`, and counts a negative result as 0.
- Channel mix counts only live units' nights in the range, adds up to 100%, and puts a manual and a channel Booking.com booking in one source.
- An empty range shows the empty text, not 0. A range with stays but no statistics shows "No night audit figures" for the three occupancy tiles only.
- The alert and the page header show on both tabs; quick actions and You're live only on Today.
- The query count is the same for 7 days and 12 months.

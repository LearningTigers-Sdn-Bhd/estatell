# Admin dashboard

## Purpose

The first page a platform admin sees at `/admin`. It answers two questions: what needs me now, and how is the platform doing? Every figure links to the page behind it. The page only reads; it has no actions of its own.

## Layout

The Dashboard archetype, without an alert or quick actions:

| Row | Content                                                                    |
| --- | -------------------------------------------------------------------------- |
| 1   | Four status tiles: Live, Onboarding, Pending approval, Suspended           |
| 2   | **To do** (two thirds) beside **This month** and **Commissions** (a third) |
| 3   | **Recent properties**, a `DataTable` with **View all** to Properties       |

On mobile everything stacks: the status tiles in a 2×2 grid, then To do, the side tiles and the table.

`Admin\DashboardController@show` sends two deferred props, `todo` and `summary`. Each card shows a skeleton until its prop loads.

## To do

`App\Services\Platform\AdminDashboardTodo`. One row per problem, with its count, a label and an **Open** link to the list filtered to it. A click anywhere on the row opens it. Rows with nothing to count are left out. The badge on the card counts all rows' items.

| Row                    | Counts                                                    | Opens                                                   |
| ---------------------- | --------------------------------------------------------- | ------------------------------------------------------- |
| Waiting for approval   | Properties in Pending approval                            | Properties, `filter[status]=pending_approval`           |
| New enquiries          | Contact enquiries with status New                         | Enquiries, `filter[status]=new`                         |
| Channel errors         | One row per property: its channel connections in Error    | That property's Channels tab                            |
| Webhook failed/refused | Deliveries now Failed, and now Refused (two rows)         | Webhook deliveries, `filter[status]=…`                  |
| Email bounced/failed   | Mail log rows Bounced, and Failed (two rows), last 7 days | Mail log, `filter[status]=…&filter[from]=…`             |
| Sign-in lockouts       | Sign-in log rows Locked out since the start of yesterday  | Sign-in log, `filter[result]=locked_out&filter[from]=…` |

- A property whose channel manager link is in Error, with no connection in Error, still gets a channel row with a count of 1.
- Webhook rows have no time window. A Failed or Refused delivery changes status only when an admin retries it, so each one is still open work.
- Mail and sign-in rows use Malaysia days, the same as the filters on those pages, so the count matches the list it opens.
- Each status gets its own row because the lists filter by one status at a time.
- With no rows: "Nothing needs you right now".

## Tiles

`App\Services\Platform\AdminDashboardSummary`.

| Tile              | Shows                                                                                                      | Opens                         |
| ----------------- | ---------------------------------------------------------------------------------------------------------- | ----------------------------- |
| Status (×4)       | Properties in that status                                                                                  | Properties on that status     |
| This month        | Properties created since the first of this month (Malaysia time), and partners with status Active          | Partners                      |
| Commissions       | The next payout and its date, and what properties owe Estatell, from `CommissionTotals` for all properties | Commissions                   |
| Recent properties | The latest 5 properties by sign-up or go-live, whichever came last: code, name, status, change and day     | The property (click on a row) |

## Tests

`tests/Feature/Admin/AdminDashboardTest.php`: staff get 404; each To do row counts and links right, and old rows stay out; the empty To do; the status counts, this month, partners and the order of recent properties.

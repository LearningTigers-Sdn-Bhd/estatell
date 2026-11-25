# Admin dashboard

## Purpose

The first page a platform admin sees at `/admin`. It answers two questions: what needs me now, and how is the platform doing? Every figure links to the page behind it. The page only reads; it has no actions of its own.

## Layout

The Dashboard archetype, without an alert or quick actions:

| Row | Content                                                                 |
| --- | ----------------------------------------------------------------------- |
| 1   | **Properties** donut (a third) beside **Estatell revenue** (two thirds) |
| 2   | **To do** (two thirds) beside **Growth** and **Next payout** (a third)  |
| 3   | **Recent properties**, a `DataTable` with **View all** to Properties    |

On mobile everything stacks in that order.

`Admin\DashboardController@show` sends two deferred props, `todo` and `summary`. Each card shows a skeleton until its prop loads.

## To do

`App\Services\Platform\AdminDashboardTodo`. One row per problem, with its count, a label and an **Open** link to the list filtered to it. A click anywhere on the row opens it. Rows with nothing to count are left out. The badge on the card counts all rows' items.

| Row                    | Counts                                                                                                                | Opens                                                   |
| ---------------------- | --------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------- |
| Waiting for approval   | Properties in Pending approval                                                                                        | Properties, `filter[status]=pending_approval`           |
| New enquiries          | Contact enquiries with status New                                                                                     | Enquiries, `filter[status]=new`                         |
| Night audit behind     | One row per Live property whose Open business date is more than a day behind its calendar, as in the front desk alert | The admin property page                                 |
| Channel errors         | One row per property: its channel connections in Error                                                                | That property's Channels tab                            |
| No new bookings        | One row per property Live for 14 days or more with no reservation created in the last 14 days                         | The admin property page                                 |
| Stuck in onboarding    | Onboarding properties with no onboarding activity (or, without any, no sign-up) for 14 days                           | Properties, `filter[status]=onboarding`                 |
| Webhook failed/refused | Deliveries now Failed, and now Refused (two rows)                                                                     | Webhook deliveries, `filter[status]=…`                  |
| Email bounced/failed   | Mail log rows Bounced, and Failed (two rows), last 7 days                                                             | Mail log, `filter[status]=…&filter[from]=…`             |
| Sign-in lockouts       | Sign-in log rows Locked out since the start of yesterday                                                              | Sign-in log, `filter[result]=locked_out&filter[from]=…` |

- A property whose channel manager link is in Error, with no connection in Error, still gets a channel row with a count of 1.
- Webhook rows have no time window. A Failed or Refused delivery changes status only when an admin retries it, so each one is still open work.
- Mail and sign-in rows use Malaysia days, the same as the filters on those pages, so the count matches the list it opens.
- Each status gets its own row because the lists filter by one status at a time.
- With no rows: "Nothing needs you right now".

## Cards

`App\Services\Platform\AdminDashboardSummary`, with the monthly figures from `AdminDashboardTrends`.

| Card              | Shows                                                                                                                     | Opens                               |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------- | ----------------------------------- |
| Properties        | A donut by status with the total in the middle, a legend row per status with its count                                    | Each row: Properties on that status |
| Estatell revenue  | Commission income per month for six months, stacked by service (booking engine, channel manager), with this month's total | Commissions (title)                 |
| Growth            | Sign-ups (`created_at`) and go-lives (`went_live_at`) per month for six months, as two lines                              | —                                   |
| Next payout       | The next payout and its date from `CommissionTotals`. What properties owe Estatell shows only when it is above zero       | Commissions                         |
| Recent properties | The latest 5 properties by sign-up or go-live, whichever came last: code, name, status, change and day                    | The property (click on a row)       |

## Charts

- Months are calendar months in Malaysia time, this month included.
- Income is the sum of `commission` entries shown as a positive number, the same as "Estatell income" in the [ledger totals](../30-commission-fees/03-ledger.md#totals). Reversals are commission entries too, so they net out in the month they are written.
- The server sums the amounts and sends decimal strings. The browser only draws them.
- Status colours use the status tokens (`success`, `info`, `warning`, `destructive`), the same tones as the status badges. Every slice is named in the legend, so colour never carries the meaning alone.

## Tests

`tests/Feature/Admin/AdminDashboardTest.php`: staff get 404; each To do row counts and links right, and old rows stay out; the night audit, quiet and onboarding rows; the empty To do; the status counts and the order of recent properties; the monthly sign-ups, go-lives and income by service.

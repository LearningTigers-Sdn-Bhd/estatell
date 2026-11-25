# Usage and cost

## Purpose

Know what the assistant does and what it costs, per property. The same rows feed the monthly cap, the add-on bill and the analysis of how guests use it.

## Data model

Plain columns, no jsonb.

```
ai_threads       id (UUID), property_id, place (AiPlace), status (AiThreadStatus),
                 reservation_unit_id (nullable), user_id (nullable, staff),
                 ai_connection_id (nullable), external_ref_hash (nullable),
                 language (nullable), escalated_at (nullable),
                 last_message_at, timestamps

ai_turns         id, property_id, ai_thread_id, place (AiPlace), language (nullable),
                 model, input_tokens, output_tokens, cached_input_tokens,
                 cost_micros, outcome (AiOutcome), duration_ms,
                 reservation_id (nullable), created_at

ai_turn_tools    id, ai_turn_id, tool (AiTool), succeeded (bool)
```

- `AiThreadStatus`: Open, Escalated, Closed, with `label()`, `tone()` and `canTransitionTo()`. A thread with no message for 7 days is Closed by a daily job.
- `AiOutcome`: Answered, QuoteSent, Booked, Escalated, Refused, Failed, CapReached, with `label()` and `tone()`.
- `language` is the language of the person's message, as the model reports it (ISO 639-1, for example `ms`, `en`, `zh`).
- `cost_micros` is the provider's price in millionths of a US dollar, from the tokens and the model's price in `config/ai-pricing.php`. OpenAI bills in dollars, so the log does too.
- `reservation_id` is filled when a [quote](03-places-and-routes.md#quote-links) from that turn becomes a booking. The outcome then moves to Booked.
- The turn row is written by `RecordAiTurn` (an Action), called from a listener on the SDK's agent events (see [To verify](01-overview.md#to-verify)).
- Message text lives in the SDK's conversation tables and is deleted after 90 days. `ai_turns` and `ai_turn_tools` keep no text, so they stay.

## Monthly cap

- The platform admin sets `property_settings.ai_monthly_cap_micros` on the admin property page. Empty means no cap.
- Before each turn, `AiMonthlyUsage` sums `cost_micros` for the property's calendar month (property time zone). At or over the cap, the turn makes no model call: the guest gets the handover message, and the turn is logged as CapReached with zero tokens.
- At 80% of the cap, the platform admins get one notification for that month.

## Add-on bill

The AI assistant is billed as an add-on. How much to charge is a sales decision and not in this plan. The Usage tab and the admin page show the month's cost, so the bill can be set from it.

## Usage tab

Settings › AI assistant › Usage, for one month (a month picker, this month by default). Read by `AiUsageReport` (a Service).

| Section     | Shows                                                |
| ----------- | ---------------------------------------------------- |
| Totals      | Turns, threads, cost in USD, cap used                |
| By place    | Turns and cost per place                             |
| By language | Threads per language                                 |
| Outcomes    | Answered, quotes sent, bookings, handovers, failures |
| Bookings    | Bookings made from quotes, with their room revenue   |
| Tools       | How often each tool ran, and how often it failed     |

The tab shows counts only, never message text.

## Questions it answers

| Question                            | From                                         |
| ----------------------------------- | -------------------------------------------- |
| Which place do people use most?     | `ai_turns.place`                             |
| Which languages do guests write in? | `ai_threads.language`                        |
| What does each property cost us?    | `ai_turns.cost_micros`                       |
| How often must staff step in?       | Outcome Escalated, `ai_threads.escalated_at` |
| How many chats become bookings?     | Outcome Booked, `reservation_id`             |
| Which tools fail?                   | `ai_turn_tools.succeeded`                    |

# Usage and cost

## Purpose

Know what the assistant does and what it costs, per property. Each hotel pays OpenAI on its own [API key](01-overview.md#api-key), so the same rows show the hotel what it spends, and feed the monthly cap and the analysis of how guests use it.

## Data model

Plain columns, no jsonb.

```
ai_threads       id (UUID), property_id, place (AiPlace), status (AiThreadStatus),
                 agent_conversation_id (unique, the SDK conversation),
                 reservation_unit_id (nullable), user_id (nullable, staff),
                 ai_connection_id (nullable), external_ref_hash (nullable),
                 language (nullable), escalated_at (nullable),
                 handover_summary (text, nullable), resolved_at (nullable),
                 resolved_by_user_id (nullable),
                 last_message_at, timestamps

ai_turns         id, property_id, ai_thread_id, place (AiPlace), language (nullable),
                 model, input_tokens, output_tokens, cached_input_tokens,
                 cost_micros, outcome (AiOutcome), duration_ms,
                 reservation_id (nullable), created_at

ai_turn_tools    id, ai_turn_id, tool (AiTool), succeeded (bool)
```

- `AiThreadStatus`: Open, Escalated, Closed, with `label()`, `tone()` and `canTransitionTo()`. Open goes to Escalated or Closed. Escalated goes to Closed, or back to Open when staff resolve it in the [AI chats inbox](03-places-and-routes.md#ai-chats-inbox). Closed stays closed. A thread with no message for 7 days is Closed by a daily job.
- `handover_summary`, `resolved_at` and `resolved_by_user_id` come with the AI chats inbox. A new handover replaces the summary and clears `resolved_at` and `resolved_by_user_id`.
- `AiOutcome`: Answered, QuoteSent, Booked, Escalated, Refused, Failed, CapReached, with `label()` and `tone()`.
- `language` is the language of the person's message, as the model reports it (ISO 639-1, for example `ms`, `en`, `zh`). Not filled yet: how the model reports it is decided with the Usage tab.
- `ai_connection_id` and `external_ref_hash` come with the chat API for n8n. The guest questions item creates the other columns.
- `cost_micros` is the provider's price in millionths of a US dollar, from the tokens and the model's price in `config/ai-pricing.php`. OpenAI bills in dollars, so the log does too. It is an estimate of the hotel's OpenAI bill; the bill itself is the exact amount.
- `reservation_id` is filled when a [quote](03-places-and-routes.md#quote-links) from that turn becomes a booking. The outcome then moves to Booked.
- The turn row is written by `RecordAiTurn` (an Action), called from the stream's `then()` in `ReplyInAiThread` once the reply ends. The outcome is Failed when a tool failed, Escalated when `HandOver` ran, QuoteSent when `SendQuoteLink` ran, otherwise Answered. A stream that fails (the provider is down, or the account has no credit) is logged from the stream's `catch()` as Failed with no tokens, and the error is reported to the Laravel log. The guest sees "We could not send that." When OpenAI refused the hotel's key, the key status also becomes Failing (see [API key](01-overview.md#api-key)).
- Message text lives in the SDK's conversation tables and is deleted after 90 days. The same purge sets `ai_threads.handover_summary` to null. `ai_turns` and `ai_turn_tools` keep no text, so they stay.

## Monthly cap

- The hotel sets `property_settings.ai_monthly_cap_micros` on Settings › AI assistant › [API key](01-overview.md#api-key), as it pays the bill. Staff type US dollars (`20.00`), and `AiMonthlyCap` converts to micros. Empty means no cap. Decided 2026-11-24: the cap moves from the admin property page to the hotel.
- Before each turn, `AiMonthlyUsage` sums `cost_micros` for the property's calendar month (property time zone). At or over the cap, the turn makes no model call: the guest gets the handover message, and the turn is logged as CapReached with zero tokens.
- At 80% of the cap, staff with `settings.manage` get one notification for that month.

## Add-on bill

The AI assistant is billed as a flat add-on, not by usage, because the hotel pays OpenAI itself. How much to charge is a sales decision and not in this plan.

## Usage tab

Settings › AI assistant › Usage is the hotel's spend page, for one month (a month picker, this month by default). Read by `AiUsageReport` (a Service). Under the totals: "Estimate from tokens. Your OpenAI bill shows the exact amount."

| Section     | Shows                                                |
| ----------- | ---------------------------------------------------- |
| Totals      | Spent (estimate, USD), cap used, turns, threads      |
| By place    | Turns and spend per place                            |
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
| What does each hotel spend?         | `ai_turns.cost_micros`                       |
| How often must staff step in?       | Outcome Escalated, `ai_threads.escalated_at` |
| How many chats become bookings?     | Outcome Booked, `reservation_id`             |
| Which tools fail?                   | `ai_turn_tools.succeeded`                    |

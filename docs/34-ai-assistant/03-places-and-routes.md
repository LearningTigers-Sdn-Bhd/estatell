# Places and routes

## Purpose

Where the chat appears, the routes behind it, how a quote becomes a booking, the chat API for n8n, the handover and the AI chats inbox.

## Chat UI

The guest and staff chats use **shadcn AI Elements** (Conversation, Message) with `useChat` from `@ai-sdk/react`. The server streams the reply with the SDK's Vercel data protocol. The components are in `components/ui/` (`conversation.tsx`, `message.tsx`), changed from `asChild` to `render` for Base UI. The message box is our own `InputGroup` with a textarea, not AI Elements' Prompt input: that one uses `asChild` and adds attachments, which the chat does not take. One shared `components/pms/ai-chat/ai-chat-sheet.tsx` wraps them for the web places.

The browser sends each message as `{ id, text }`: text only, so no file reaches the model. The server builds the SDK message from it.

Every chat shows "You are chatting with an AI assistant" and a link to contact the front desk.

## Routes

| Route                                                                                 | Place           | Auth                                                |
| ------------------------------------------------------------------------------------- | --------------- | --------------------------------------------------- |
| `GET`, `POST /stay/{code}/ai/messages`                                                | Concierge lobby | None; throttled by address and property             |
| `GET`, `POST /c/{token}/ai/messages`                                                  | Concierge stay  | The stay token                                      |
| `POST /api/booking/v1/properties/{code}/ai/messages`                                  | Booking engine  | Booking client token (`booking:*`) and `X-Guest-IP` |
| `POST /p/{property:code}/ai/messages`                                                 | Staff           | Staff session                                       |
| `POST /api/ai/v1/messages`                                                            | API             | AI connection token (`ai:chat`)                     |
| `GET /p/{property:code}/ai-chats` (`?thread={id}` opens one)                          | AI chats inbox  | `front-desk.view`                                   |
| `POST /p/{property:code}/ai-chats/{thread}/resolve`                                   | AI chats inbox  | `front-desk.view`                                   |
| `GET /p/{property:code}/settings/ai-assistant` (Places), `/capabilities`, `/handover` | Settings        | `settings.manage`                                   |
| `PUT /p/{property:code}/settings/ai-assistant/scopes`, `/handover-message`            | Settings        | `settings.manage`                                   |
| `GET`, `PUT`, `DELETE /p/{property:code}/settings/ai-assistant/key`                   | Settings        | `settings.manage`                                   |
| `POST /p/{property:code}/settings/ai-assistant/key/test`, `PUT .../monthly-cap`       | Settings        | `settings.manage`                                   |
| `GET /p/{property:code}/settings/ai-assistant/connections`                            | Settings        | `settings.manage`                                   |
| `GET /p/{property:code}/settings/ai-assistant/usage`                                  | Settings        | `settings.manage`                                   |

Every chat route sits behind the `ai-assistant` middleware and checks its place [scope](01-overview.md#scopes) through `AiScopes`. A scope that is off returns 404. A cap that is reached, or a key that is Failing, returns the handover message with no model call (see [Monthly cap](04-usage-and-cost.md#monthly-cap) and [API key](01-overview.md#api-key)).

Throttles: lobby 10 messages a minute per address and property; stay and booking engine 20 a minute per thread; API 60 a minute per connection.

The browser keeps its thread id: the lobby and the booking engine in local storage, the stay with the token, staff per user. `GET` returns `{ thread, messages }` so the Sheet shows the history when it opens. A lobby id that belongs to another property, place or a closed thread gets 409, and the browser starts a new id.

## Concierge lobby and stay

- An **Ask us** button on the lobby hub and the stay screen opens the chat in a Sheet. It shows only while the Concierge scope is on (`aiChat` prop).
- Lobby: questions and quotes. No stay is known, so no stay tools.
- Stay: the same, plus `ReadMyStay` and `MakeGuestRequest`. The thread is tied to the stay's reservation unit when it starts. A sent request shows as a Request sent card that links to the stay's requests.

## Quote links

Decided 2026-11-24: the Concierge page sells future dates itself. Details settled on 2026-11-24.

1. `CheckRoomsAndPrices` calls `KioskWalkIn::quotable`: every room type and plan that is free, nightly and bookable online, priced with `BookingQuote`, the same code that books the stay. The price on the card is the price the folio will charge.
2. The guest picks one. `SendQuoteLink` calls `CreateConciergeQuote`, which checks the arrival is within `BookingWindow::MONTHS_AHEAD`, saves a `concierge_quotes` row and returns its link and id.
3. The guest opens `GET /stay/{property:code}/quote/{quote}`. The parameter is not named `token`, because the `concierge` middleware reads `{token}` as a stay link. The page prices the same room type and plan again with `KioskWalkIn::quotable`. If the price changed, it shows the new price first. The form is the walk-in details form: the returning-guest match, or name, ID and contact.
4. The guest presses Book and continue. `ConfirmConciergeQuote` locks the quote, then books and holds the room and saves the details in **one transaction** through `ConfirmConciergeWalkIn` with the quote's rate plan. Nothing is booked before this step.
5. The guest lands on the stay page `/c/{token}` at the Payment step and pays the **whole stay**: the unit's whole bill with its taxes, plus the security deposit. A walk-in pays only Collect now. An unpaid hold is released after `BookWalkIn::HOLD_MINUTES` by `ReleaseExpiredKioskHolds`.

```
concierge_quotes   id, property_id, token (unique), room_type_id, rate_plan_id,
                   arrival_date, departure_date, adults, quoted_total,
                   ai_turn_id (nullable), reservation_id (nullable),
                   expires_at, timestamps
```

| Rule           | Detail                                                                                                                                                                                                                                                                                                                                                                                         |
| -------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Life           | A quote lasts 24 hours. An expired link shows a message and a link back to the chat.                                                                                                                                                                                                                                                                                                           |
| Price          | `quoted_total` is what the guest was told. The booking always takes the price at step 4.                                                                                                                                                                                                                                                                                                       |
| Plans          | Bookable-online plans only. A property with none gets no quotes, and the assistant hands over.                                                                                                                                                                                                                                                                                                 |
| Party          | Adults only. A guest who mentions children is handed over.                                                                                                                                                                                                                                                                                                                                     |
| Walk-in switch | Not needed. The RoomQuotes scope is the hotel's switch for quotes. `BookConciergeWalkIn` skips the walk-in switch for a quote.                                                                                                                                                                                                                                                                 |
| Hold limit     | Shared with walk-ins: `BookConciergeWalkIn::MAX_OPEN_HOLDS` counts both.                                                                                                                                                                                                                                                                                                                       |
| Used twice     | Once `reservation_id` is set, the link opens the stay page instead of booking again.                                                                                                                                                                                                                                                                                                           |
| Rate plan      | `BookWalkIn::book` and `ConfirmConciergeWalkIn` take an optional rate plan. Without one they keep the cheapest plan, as the kiosk and walk-in do today.                                                                                                                                                                                                                                        |
| Payment        | Whole stay up front (decided 2026-11-24): `SelfCheckInPayment::shortfall` with `wholeStay`, chosen by `ConciergeQuoteStays::isQuoted`. Collect now alone could be 0, and the 10-minute hold would then cancel a booking the guest thinks is made. The quote page and the chat card say the whole stay is paid when booking; the exact amount with taxes and deposit shows on the Payment step. |
| Wording        | The Payment and Hold expired steps say "confirm your booking" and "Ask us again in the chat" when the stay came from a quote.                                                                                                                                                                                                                                                                  |
| Known gap      | A quote booked before its turn is logged (a fraction of a second after the reply) keeps its turn as QuoteSent, not Booked.                                                                                                                                                                                                                                                                     |

**Link to the AI turn.** A turn is written only after the stream ends, so `CreateConciergeQuote` cannot know it. `SendQuoteLink` returns the quote id. `RecordAiTurn` sets the outcome to QuoteSent and calls the Concierge Action `AttachConciergeQuoteToAiTurn` to fill `ai_turn_id`. On confirm, Concierge fires `ConciergeQuoteBooked`. An AI listener calls `MarkAiTurnBooked`, which sets `ai_turns.reservation_id` and the outcome Booked. Each module writes only its own tables.

**In the chat.** The room options and the quote link are cards drawn from the tool output (`tool-CheckRoomsAndPrices`, `tool-SendQuoteLink` parts), not from the model's text. So the price on screen always comes from the tool.

The Concierge module owns the table, the page and the quote Actions. The AI module only calls `CreateConciergeQuote` and `AttachConciergeQuoteToAiTurn`.

On the **booking engine**, `SendQuoteLink` returns a link to the microsite's checkout with the room, plan and dates filled in. The microsite makes the hold as today, priced with `BookingOffers`.

## Booking engine

A chat bubble on the booking website. The microsite calls the booking API route with its booking client token and passes the stream through (see [To verify](01-overview.md#to-verify)). Tools: facts, prices and quote links only. It never reads a booking.

## Staff

An **Ask Estatell** button in the navbar opens the chat in a Sheet on any staff page. Read only: the answers link to the page where staff act. Tools: `DailyNumbers`, `HowTo` and `HandOver` (which here tells support, not the front desk).

## Chat API for n8n

For WhatsApp or any other channel run by the hotel's own n8n.

- **AI connections**: Settings › AI assistant › Connections lists them. Create one with a name ("n8n – WhatsApp"); the token (ability `ai:chat`, Sanctum, like [API tokens](../28-api-access/01-api-tokens.md)) is shown once. Revoke turns it off.
- `ai_connections`: id, property_id, name, is_active, last_used_at, timestamps. It owns its tokens with `HasApiTokens`.

```
POST /api/ai/v1/messages
Authorization: Bearer <token>
Idempotency-Key: <n8n execution id>

{ "channel": "whatsapp", "thread": "<id from the channel>", "message": "Room for 2 on 12 Dec?", "guest_name": "Aisha" }

200 { "thread_id": "...", "reply": "...", "escalated": false }
```

- `thread` is the channel's own id, such as the WhatsApp chat id. It is stored as an HMAC, like `guest_ip_hash`, never the phone number.
- A phone number never proves who the guest is, so the API place has no stay tools.
- The reply is plain JSON, not a stream. n8n sends it back on WhatsApp.
- The idempotency key works like the [kiosk writes](../28-api-access/03-kiosk-check-in-endpoints.md#idempotency-for-every-kiosk-write): a retry gets the same reply and makes no second turn.

## Handover

| What happens   | Where                                                                                    |
| -------------- | ---------------------------------------------------------------------------------------- |
| The guest sees | The handover message from Settings, and the front desk contact                           |
| Staff get      | The `AiThreadEscalated` notification, with the place, guest name if known, and a summary |
| Webhook        | `ai.escalated`, with the thread id, place, reservation id if any and the summary         |
| The thread     | Status Escalated. The assistant still answers                                            |

The summary is written by the model in one or two sentences, as the `summary` argument of the `HandOver` call, so it costs no extra model call. When it is empty, the `reason` is used. It is cut at 500 characters. The webhook and the notification never carry the full chat. With the inbox, the summary is also saved as `ai_threads.handover_summary`, so the list can show it (see [AI chats inbox](#ai-chats-inbox)).

The notification goes to the active staff with `front-desk.view`. It opens the chat in the AI chats inbox (`?thread={id}`). Only the first handover of a thread sends: a second `HandOver` call, or one on a Closed thread, sends nothing. After staff resolve a handover, a new handover in the same thread sends again.

## AI chats inbox

Built on 2026-11-24. Staff read what the assistant and the guest said, contact the guest their own way (phone, WhatsApp, the desk), and mark the handover resolved. Staff do not reply inside the guest's chat.

| Part    | What it is                                                                                                                                                                                                                                                                                           |
| ------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Nav     | **AI chats** in the Front desk nav, after Requests. Shown when the AI assistant feature is on and the user has `front-desk.view`.                                                                                                                                                                    |
| Tabs    | **Handed over** (the default: status Escalated, oldest handover first) and **All** (every thread, last message first). The Handed over tab shows its count.                                                                                                                                          |
| Columns | Last message (time ago), Place, Guest (the stay's guest and room, or "Lobby guest"), Summary (the handover summary, else the guest's last message, one line), Status (`AiThreadStatus` tone), Turns.                                                                                                 |
| Filters | Place, and a date range on the last message. 25 rows a page.                                                                                                                                                                                                                                         |
| Chat    | A row opens a Sheet (`?thread={id}` in the URL): the summary, the stay link to the reservation, and the full chat, read only, with AI Elements Conversation and Message. A **Resolve** button shows on a handed-over thread.                                                                         |
| Resolve | `ResolveAiThread` moves Escalated back to Open and sets `resolved_at` and `resolved_by_user_id`. It logs `ai-assistant.thread-resolved` on the property, with the thread id (the activity log has no UUID subjects). A thread that is not Escalated returns false and the page shows an error toast. |
| Purged  | A thread older than 90 days has no text: the Sheet says "The messages were deleted after 90 days." The row stays, with its place, status and turns.                                                                                                                                                  |

Resolve goes back to Open, not to Closed, so the guest keeps the same chat and its history, and a new handover alerts staff again. The daily job still closes a thread after 7 days with no message.

The inbox lists guest places only (Concierge lobby and stay, booking website, chat API). A Staff thread is one staff member's own helper chat, so it never shows here. The list is read by `AiThreadList` and the chat by `AiThreads::uiMessages()` (Services), the same read the guest chat uses. The page gets a query-count test with lazy loading prevented. Another property's thread returns 404.

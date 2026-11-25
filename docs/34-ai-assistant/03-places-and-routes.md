# Places and routes

## Purpose

Where the chat appears, the routes behind it, how a quote becomes a booking, the chat API for n8n, and the handover.

## Chat UI

The guest and staff chats use **shadcn AI Elements** (Conversation, Message, Prompt input) with `useChat` from `@ai-sdk/react`. The server streams the reply with the SDK's `usingVercelDataProtocol()`. The components go in `components/ui/` through the shadcn CLI, and one shared `components/pms/ai-chat` wraps them for the three web places.

Every chat shows "You are chatting with an AI assistant" and a link to contact the front desk.

## Routes

| Route                                                | Place           | Auth                                                |
| ---------------------------------------------------- | --------------- | --------------------------------------------------- |
| `POST /stay/{code}/ai/messages`                      | Concierge lobby | None; throttled by address and property             |
| `POST /c/{token}/ai/messages`                        | Concierge stay  | The stay token                                      |
| `POST /api/booking/v1/properties/{code}/ai/messages` | Booking engine  | Booking client token (`booking:*`) and `X-Guest-IP` |
| `POST /p/{property:code}/ai/messages`                | Staff           | Staff session                                       |
| `POST /api/ai/v1/messages`                           | API             | AI connection token (`ai:chat`)                     |
| `GET/PUT /p/{property:code}/settings/ai-assistant`   | Settings        | `settings.manage`                                   |
| `GET /p/{property:code}/settings/ai-assistant/usage` | Settings        | `settings.manage`                                   |

Every chat route sits behind the `ai-assistant` middleware and checks the place switch. A switch that is off returns 404. A cap that is reached returns the handover message with no model call (see [Monthly cap](04-usage-and-cost.md#monthly-cap)).

Throttles: lobby 10 messages a minute per address and property; stay and booking engine 20 a minute per thread; API 60 a minute per connection.

The browser keeps its thread id: the lobby and the booking engine in local storage, the stay with the token, staff per user.

## Concierge lobby and stay

- A **chat** button on the lobby hub and the stay screen opens the chat in a Sheet.
- Lobby: questions and quotes. No stay is known, so no stay tools.
- Stay: the same, plus `ReadMyStay` and `MakeGuestRequest`. The thread is tied to the stay's reservation unit when it starts.

## Quote links

Decided 2026-11-24: the Concierge page sells future dates itself.

1. `SendQuoteLink` calls `CreateConciergeQuote`, which saves a `concierge_quotes` row and returns its link.
2. The guest opens `GET /stay/{code}/quote/{token}`. The page re-prices the stay. If the price changed, it shows the new price first.
3. The guest sends their details. `ConfirmConciergeQuote` books and holds the room and saves the details in **one transaction**, like [`ConfirmConciergeWalkIn`](../30-concierge/01-overview.md#walk-in). Nothing is booked before this step.
4. The guest pays on `/c/{token}`, as any other guest. An unpaid hold is released by the same job.

```
concierge_quotes   id, property_id, token (unique), room_type_id, rate_plan_id,
                   arrival_date, departure_date, adults, quoted_total,
                   ai_turn_id (nullable), reservation_id (nullable),
                   expires_at, timestamps
```

- A quote lasts 24 hours. An expired link sends the guest to a new search.
- `quoted_total` is what the guest was told; the booking always takes the price at step 3.
- `reservation_id` is set at step 3, which links the booking back to the AI turn for the [usage log](04-usage-and-cost.md).
- `ConfirmConciergeQuote` reuses `BookWalkIn::book` with the quote's dates, the walk-in throttles and the open hold limit. The arrival may be any date the booking engine sells (today to 6 months ahead).
- The Concierge module owns the table, the page and both Actions. The AI module only calls `CreateConciergeQuote`.

On the **booking engine**, `SendQuoteLink` returns a link to the microsite's checkout with the room, plan and dates filled in. The microsite makes the hold as today.

## Booking engine

A chat bubble on the booking website. The microsite calls the booking API route with its booking client token and passes the stream through (see [To verify](01-overview.md#to-verify)). Tools: facts, prices and quote links only. It never reads a booking.

## Staff

An **Ask Estatell** button in the navbar opens the chat in a Sheet on any staff page. Read only: the answers link to the page where staff act. Tools: `DailyNumbers`, `HowTo` and `HandOver` (which here tells support, not the front desk).

## Chat API for n8n

For WhatsApp or any other channel run by the hotel's own n8n.

- **AI connections**: Settings › AI assistant › General lists them. Create one with a name ("n8n – WhatsApp"); the token (ability `ai:chat`, Sanctum, like [API tokens](../28-api-access/01-api-tokens.md)) is shown once. Revoke turns it off.
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

The summary is written by the model in one or two sentences. The webhook and the notification never carry the full chat.

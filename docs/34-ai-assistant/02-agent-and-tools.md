# Agent and tools

## Purpose

How the assistant thinks: one agent class, its instructions, what it knows and the tools it may call. The server still decides every price, every free room and every rule.

## The agent

`App\Ai\Agents\PropertyAssistant` implements the SDK's `Agent` with `Promptable` and `RemembersConversations`.

- One agent for every place. The place decides which tools it gets (`tools()` reads the thread's `AiPlace`), and a tool whose capability [scope](01-overview.md#scopes) is off is left out.
- The model and the provider come from `config/ai.php`: OpenAI, one model for all properties. The model name is set in config, not per property.
- At most 4 tool calls per turn. A turn that needs more has misunderstood the guest; it hands over instead.
- Each thread stores its SDK conversation id in `ai_threads.agent_conversation_id`. `OpenAiThread` creates the conversation when the thread starts, with no participant (the SDK's `participant_id` is a number and the thread id is a UUID). Each turn resumes it with `continue()`. The browser sends our thread id, never a conversation id.
- Fakes from the SDK are used in tests. Tests never call OpenAI.

A new top-level folder `app/Ai/` holds `Agents/`, `Tools/` and `Middleware/`, where the SDK's generators put agents. The user approved this new top-level folder on 2026-11-24 (see [Confirmed defaults](01-overview.md#confirmed-defaults)).

## Instructions

Built per turn by `AssistantInstructions` (a Service), from:

1. The rules: answer only about this property, never invent a price or a free room, use the tools for both, hand over when unsure, never promise a cancel or a refund.
2. The place and who is asking (a lobby visitor, a guest with a stay, a staff member).
3. The [knowledge](#knowledge).
4. The language rule: reply in the language the person writes in. When unsure, use the property's default language.
5. The handover message from Settings.

## Knowledge

The Concierge content, read through `ConciergePageBuilder`'s guide data, never by reaching into Concierge models:

| Part         | Source (see [Property guide](../30-concierge/01-overview.md#property-guide-and-contact))        |
| ------------ | ----------------------------------------------------------------------------------------------- |
| The property | Name, address, check-in and check-out times, About, arrival and departure, getting here, topics |
| Amenities    | `property_amenities` with their details                                                         |
| Policies     | House rules, room terms, payment and deposits, the guest wording of the charge policies         |
| FAQs         | `property_faqs` that are switched on                                                            |
| Contact      | Front desk phone, hours and email                                                               |

- Only what a guest may see goes in. A hidden FAQ, a hidden topic and staff-only Wi-Fi never do.
- Wi-Fi passwords never go in. The stay page already shows them to a checked-in guest; the assistant points there.
- The content is a few KB per property, so it goes straight into the instructions. No embeddings.
- The Staff place adds the [user docs](01-overview.md#to-verify) instead of the guest content.

## Tools

Each tool is one class in `app/Ai/Tools/`. It validates its input, calls a Service or Action of its module, and returns plain facts. `AiTool` enum lists them, with `label()`.

| Tool                  | Calls                                                                      | Lobby | Stay | Booking engine | Staff | API |
| --------------------- | -------------------------------------------------------------------------- | :---: | :--: | :------------: | :---: | :-: |
| `PropertyFacts`       | One part of the knowledge in full by `topic`, plus the nearby places       |  yes  | yes  |      yes       |  no   | yes |
| `CheckRoomsAndPrices` | `BookingOffers` for dates and a party                                      |  yes  | yes  |      yes       |  no   | yes |
| `SendQuoteLink`       | `CreateConciergeQuote` (Concierge, API) or a microsite checkout link       |  yes  | yes  |      yes       |  no   | yes |
| `ReadMyStay`          | The stay of the thread's own `/c/{token}`: dates, room, balance due        |  no   | yes  |       no       |  no   | no  |
| `MakeGuestRequest`    | `SubmitConciergeRequest` (housekeeping, maintenance, complaint)            |  no   | yes  |       no       |  no   | no  |
| `HandOver`            | `EscalateAiThread`                                                         |  yes  | yes  |      yes       |  yes  | yes |
| `DailyNumbers`        | Arrivals, departures, in-house and unpaid counts from the existing reports |  no   |  no  |       no       |  yes  | no  |
| `HowTo`               | Searches the user docs                                                     |  no   |  no  |       no       |  yes  | no  |

Scopes: `CheckRoomsAndPrices` and `SendQuoteLink` need RoomQuotes, `ReadMyStay` needs StayDetails, `MakeGuestRequest` needs GuestRequests, `DailyNumbers` and `HowTo` need their own scope. `PropertyFacts` and `HandOver` need none. See [Scopes](01-overview.md#scopes).

Rules for every tool:

- **Prices and rooms come only from `CheckRoomsAndPrices`.** The model may repeat them, never work them out.
- **`ReadMyStay` reads only the stay of its own thread.** The thread's `reservation_unit_id` is set from the token; the model cannot pass another id. A lobby or API thread has no stay, so the tool is not given.
- **No tool cancels, refunds, moves a room, posts a charge or changes a guest.** A cancel or refund question gets the refund request page (stay) or a handover.
- **Staff tools check permissions.** `DailyNumbers` needs the report permission the matching report needs. A refusal is told to the member in plain words.
- A tool that fails returns a short reason. The agent says it cannot help and hands over. The turn's outcome is Failed.

## Handover

`EscalateAiThread` (an Action) marks the thread Escalated (built with guest questions), sends the staff notification `AiThreadEscalated` (to staff with `front-desk.view`) and queues the `ai.escalated` webhook (a new `WebhookEvent`). The guest sees the handover message from Settings. An escalated thread keeps answering questions, and staff see it in the [AI chats inbox](03-places-and-routes.md#ai-chats-inbox). See [Handover](03-places-and-routes.md#handover).

## Safety in the instructions is not enough

The instructions tell the model the rules, but the code enforces them: the tools a place gets, the thread's own stay, the permission checks and the server prices. A guest who talks the model round still cannot reach another stay or a wrong price.

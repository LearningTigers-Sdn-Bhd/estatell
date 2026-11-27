# Agent and tools

## Purpose

How the assistant thinks: one agent class, its instructions, what it knows and the tools it may call. The server still decides every price, every free room and every rule.

## The agent

`App\Ai\Agents\PropertyAssistant` implements the SDK's `Agent` with `Promptable` and `RemembersConversations`.

- One agent for every place. The place decides which tools it gets (`tools()` reads the thread's `AiPlace`), and a tool whose capability [scope](01-overview.md#scopes) is off is left out.
- The model and the provider come from `config/ai.php`: OpenAI, one model for all properties. The model name is set in config, not per property. The key is the hotel's own, built per turn with `Ai::build()` (see [API key](01-overview.md#api-key)).
- At most 4 tool calls per turn. A turn that needs more has misunderstood the guest; it hands over instead.
- Each thread stores its SDK conversation id in `ai_threads.agent_conversation_id`. `OpenAiThread` creates the conversation when the thread starts, with no participant (the SDK's `participant_id` is a number and the thread id is a UUID). Each turn resumes it with `continue()`. The browser sends our thread id, never a conversation id.
- Fakes from the SDK are used in tests. Tests never call OpenAI, except the opt-in [AI eval](05-eval.md), which runs by hand only and never in CI.

A new top-level folder `app/Ai/` holds `Agents/`, `Tools/` and `Middleware/`, where the SDK's generators put agents. The user approved this new top-level folder on 2026-11-24 (see [Confirmed defaults](01-overview.md#confirmed-defaults)).

## Instructions

Built per turn by `AssistantInstructions` (a Service), from:

1. The rules: answer only about this property, never invent a price or a free room, use the tools for both, hand over when unsure, sum up the key facts for a broad question instead of handing over, number a room list, one room and plan per line, so a number picks a line, end it with one short line on why the stay is worth it (from the facts only), never promise a cancel, a refund or a change to a booked stay, treat new dates or a new party for a quote link as a new check (the current choice first, then the others, and a confirm before a new link), and write only the message the guest reads, never its rules, steps or thoughts.
2. The place and who is asking (a lobby visitor, a guest with a stay, a staff member).
3. The [knowledge](#knowledge).
4. The language rule: reply in the language the person writes in. When unsure, use the property's default language.
5. The handover message from Settings.
6. Today at the property, last so the parts before it stay the same all day for the prompt cache. It carries worked examples of a date without a year, built from today: a month still to come is this year's, a month already gone is next year's (on 27 November 2026, "dec" is December 2026 and "aug" is August 2027). The model never asks the year and writes it in the reply. Decided 2026-11-27, after a reply on WhatsApp showed the model arguing the year out loud.

## Knowledge

The Concierge content, read through `ConciergePageBuilder`'s guide data, never by reaching into Concierge models:

| Part         | Source (see [Property guide](../30-concierge/01-overview.md#property-guide-and-contact))                                                                                                                    |
| ------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| The property | Name, address, check-in and check-out times, About, arrival and departure, getting here, topics                                                                                                             |
| Amenities    | `property_amenities` with their details                                                                                                                                                                     |
| Rooms        | Room types sold online (`BookingContent::roomTypes`, as the booking site shows them): description, max adults and children, smoking, pets, room amenities. No prices; those come from `CheckRoomsAndPrices` |
| Policies     | House rules, room terms, payment and deposits, the guest wording of the charge policies                                                                                                                     |
| FAQs         | `property_faqs` that are switched on                                                                                                                                                                        |
| Contact      | Front desk phone, hours and email                                                                                                                                                                           |

- Only what a guest may see goes in. A hidden FAQ, a hidden topic and staff-only Wi-Fi never do.
- Wi-Fi passwords never go in. The stay page already shows them to a checked-in guest; the assistant points there.
- The content is a few KB per property, so it goes straight into the instructions. No embeddings.
- The Staff place adds the [user docs](01-overview.md#to-verify) instead of the guest content.

## Tools

Each tool is one class in `app/Ai/Tools/`. It validates its input, calls a Service or Action of its module, and returns plain facts. `AiTool` enum lists them, with `label()`.

| Tool                  | Calls                                                                      | Lobby | Stay | Booking engine | Staff | API |
| --------------------- | -------------------------------------------------------------------------- | :---: | :--: | :------------: | :---: | :-: |
| `PropertyFacts`       | One part of the knowledge in full by `topic`, plus the nearby places       |  yes  | yes  |      yes       |  no   | yes |
| `CheckRoomsAndPrices` | `KioskWalkIn::quotable` (Concierge, API) or `BookingOffers` (microsite)    |  yes  | yes  |      yes       |  no   | yes |
| `SendQuoteLink`       | `CreateConciergeQuote` (Concierge, API) or a microsite checkout link       |  yes  | yes  |      yes       |  no   | yes |
| `ReadMyStay`          | The stay of the thread's own `/c/{token}`: dates, room, balance due        |  no   | yes  |       no       |  no   | no  |
| `MakeGuestRequest`    | `SubmitConciergeRequest` (housekeeping, maintenance, complaint)            |  no   | yes  |       no       |  no   | no  |
| `HandOver`            | `EscalateAiThread`                                                         |  yes  | yes  |      yes       |  yes  | yes |
| `DailyNumbers`        | Arrivals, departures, in-house and unpaid counts from the existing reports |  no   |  no  |       no       |  yes  | no  |
| `HowTo`               | Searches the user docs                                                     |  no   |  no  |       no       |  yes  | no  |

Scopes: `CheckRoomsAndPrices` and `SendQuoteLink` need RoomQuotes, `ReadMyStay` needs StayDetails, `MakeGuestRequest` needs GuestRequests, `DailyNumbers` and `HowTo` need their own scope. `PropertyFacts` and `HandOver` need none. See [Scopes](01-overview.md#scopes).

Rules for every tool:

- **Prices and rooms come only from `CheckRoomsAndPrices`.** The model may repeat them, never work them out.
- **`CheckRoomsAndPrices` needs the number of adults.** When a guest gives only a total ("4 people"), the model asks how many are adults first and never assumes everyone is. A number of adults means no children unless the guest says so. Any mention of a baby, kid or child (anak, bayi, 小孩 and so on) means children: the model asks each age and passes `child_ages` to both quote tools. See [Quote links](03-places-and-routes.md#quote-links).
- **`ReadMyStay` reads only the stay of its own thread.** The thread's `reservation_unit_id` is set from the token; the model cannot pass another id. A lobby or API thread has no stay, so the tool is not given.
- **`ReadMyStay` takes no input.** It returns the reference, status, dates, nights, room type, guests and the balance due (`ConciergeBill::due`). The room number is given only once the guest is checked in, as on the stay page, and is the room of the latest night so far.
- **`MakeGuestRequest` sends only housekeeping, maintenance or a complaint**, through `SubmitConciergeRequest`, so the limits of the stay page's request form apply (5 open requests, a checked-in guest for room work). The model repeats the request and sends it only after the guest says yes. A refusal returns its own sentence for the guest. A sent request shows as a card in the chat, with a link to the stay's requests. The turn stays Answered; `ai_turn_tools` records the tool.
- **No tool cancels, refunds, moves a room, posts a charge or changes a guest.** A cancel or refund question gets the refund request page (stay) or a handover.
- **Staff tools check permissions.** `DailyNumbers` needs the report permission the matching report needs. A refusal is told to the member in plain words.
- A tool that fails returns a short reason. The agent says it cannot help and hands over. The turn's outcome is Failed.

## Handover

`EscalateAiThread` (an Action) marks the thread Escalated (built with guest questions), sends the staff notification `AiThreadEscalated` (to staff with `front-desk.view`) and queues the `ai.escalated` webhook (a new `WebhookEvent`). The guest sees the handover message from Settings. An escalated thread keeps answering questions, and staff see it in the [Chat handovers inbox](03-places-and-routes.md#chat-handovers-inbox). See [Handover](03-places-and-routes.md#handover).

## Safety in the instructions is not enough

The instructions tell the model the rules, but the code enforces them: the tools a place gets, the thread's own stay, the permission checks and the server prices. A guest who talks the model round still cannot reach another stay or a wrong price.

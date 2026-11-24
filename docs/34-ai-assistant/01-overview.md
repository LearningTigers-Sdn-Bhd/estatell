# AI assistant

> **Planned.** Researched on 2026-11-20, decided on 2026-11-24. Nothing is built. The build items are in [checklist.md](../checklist.md#34-ai-assistant).

## Purpose

Guests ask the same questions every day: breakfast, parking, pets, a room for next weekend. Staff ask "how do I…?" and "how many arrivals tomorrow?". The AI assistant answers these in a chat, in the guest's own language, and hands over to staff when it cannot help.

## Decisions

| #   | Decision                                                                                                                                                | Date       |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------- |
| 1   | One **AI assistant** module, not a part of the Concierge page. The places share the same knowledge, handover and usage log; only the tools change.      | 2026-11-20 |
| 2   | The package is `laravel/ai` (the Laravel AI SDK).                                                                                                       | 2026-11-24 |
| 3   | The provider is **OpenAI**, on Estatell's own key. Hotels do not need an AI account or a key.                                                           | 2026-11-24 |
| 4   | The chat UI uses **shadcn AI Elements**, which needs the npm packages `ai` and `@ai-sdk/react`.                                                         | 2026-11-24 |
| 5   | The Concierge page books **future dates** itself, through a quote link. No microsite is needed. See [Quote links](03-places-and-routes.md#quote-links). | 2026-11-24 |
| 6   | No MCP server. Outside tools such as n8n use the [chat API](03-places-and-routes.md#chat-api-for-n8n).                                                  | 2026-11-20 |
| 7   | The AI never sets a price, cancels, refunds, or changes a folio. The staff helper is **read only**.                                                     | 2026-11-20 |
| 8   | Every AI turn is a row in the [usage log](04-usage-and-cost.md), for analysis, the monthly cap and the add-on bill.                                     | 2026-11-20 |

## Terms

| Term       | Means                                                                                                      |
| ---------- | ---------------------------------------------------------------------------------------------------------- |
| Place      | Where the assistant answers: Concierge lobby, Concierge stay, Booking engine, Staff, API                   |
| Thread     | One chat with one person in one place, kept as an `ai_threads` row                                         |
| Turn       | One message in and one reply out, with every tool the agent called for it. One row in `ai_turns`           |
| Tool       | A PHP class the model may call. Each tool calls an existing Service or Action                              |
| Handover   | The assistant stops and tells staff. Staff get a notification and the `ai.escalated` webhook fires         |
| Quote link | A link the assistant sends with a room, a plan and dates filled in. Nothing is booked until the guest acts |

## Places

| Place           | Who                                  | Needs on                                                 | Switch                                                              |
| --------------- | ------------------------------------ | -------------------------------------------------------- | ------------------------------------------------------------------- |
| Concierge lobby | Anyone with the lobby link           | `AiAssistant` and `Concierge`                            | `property_settings.ai_concierge_enabled`                            |
| Concierge stay  | The guest on `/c/{token}`            | `AiAssistant` and `Concierge`                            | `property_settings.ai_concierge_enabled`                            |
| Booking engine  | Visitors of the booking website      | `AiAssistant`, `BookingEngine` and a published microsite | `property_settings.ai_booking_engine_enabled`                       |
| Staff           | Signed-in staff                      | `AiAssistant`                                            | `property_settings.ai_staff_enabled`                                |
| API             | n8n or another tool with an AI token | `AiAssistant`                                            | An active [AI connection](03-places-and-routes.md#chat-api-for-n8n) |

`AiPlace` enum: `ConciergeLobby`, `ConciergeStay`, `BookingEngine`, `Staff`, `Api`, with `label()`.

## Feature flag

Pennant feature `AiAssistant` (`App\Features\AiAssistant`, `#[Name('ai-assistant')]`), off by default, like [Facilities](../33-facilities/01-overview.md#feature-flag). A platform admin turns it on per property. `PlatformFeature::AiAssistant` lists it. With the feature off, the settings item, every chat and every AI route are hidden, and the routes return 404 through an `ai-assistant` middleware. Turning it off keeps all data.

The platform admin also sets the property's **monthly cap** on the same admin page. See [Monthly cap](04-usage-and-cost.md#monthly-cap).

## Settings

**Settings › AI assistant** follows the Settings archetype with tab links.

| Tab     | Holds                                                                                                   |
| ------- | ------------------------------------------------------------------------------------------------------- |
| General | The switch for each place, the handover message the guest sees, and the AI connections for the chat API |
| Usage   | The [usage report](04-usage-and-cost.md#usage-tab) for a month                                          |

The place switches are `property_settings` booleans, off by default. A place switch shows only when the features it needs are on. The assistant's knowledge is the Concierge content (FAQs, policies, amenities and so on), so there is no separate knowledge page. See [Knowledge](02-agent-and-tools.md#knowledge).

## Files

| File                                         | Covers                                                                |
| -------------------------------------------- | --------------------------------------------------------------------- |
| [Agent and tools](02-agent-and-tools.md)     | The agent, its instructions, knowledge, languages and each tool       |
| [Places and routes](03-places-and-routes.md) | Each place's routes and UI, quote links, the chat API and handover    |
| [Usage and cost](04-usage-and-cost.md)       | `ai_threads`, `ai_turns`, the cost, the monthly cap and the Usage tab |

## Permissions

| Permission        | Allows                                                      |
| ----------------- | ----------------------------------------------------------- |
| `settings.manage` | Settings › AI assistant, including the Usage tab (existing) |

The staff helper needs no new permission. Its tools run as the signed-in staff member and check that member's permissions, so it never shows what the member could not open.

## Personal data

Guest messages go to OpenAI. Before launch, check Malaysia's PDPA (see [To verify](#to-verify)).

- Every chat shows a line saying the guest is talking to an AI assistant.
- Tools never send ID numbers, dates of birth, payment details, door PINs or Wi-Fi passwords to the model.
- Logs hold ids and field names, never message text.
- Message text is kept 90 days, then a daily job deletes it. The usage log keeps no text, so it stays.

## Compared with wastays

| Part          | wastays                                                     | Estatell                                   |
| ------------- | ----------------------------------------------------------- | ------------------------------------------ |
| Size          | `app/services/ai_concierge`: 99 files, about 10,900 lines   | One agent and a few tools on existing code |
| Understanding | Ruby matchers first, then the model with 2 tools            | The model picks the tool                   |
| Booking       | Saved steps in `prospect_conversation_states` (jsonb slots) | Chat history and the quote link Actions    |
| Knowledge     | Uploaded documents, chunks and vector search                | The Concierge content in the instructions  |
| Provider      | Each hotel enters its own key (Claude, Gemini or OpenAI)    | OpenAI on Estatell's key                   |
| Usage         | No usage table                                              | One row per turn                           |
| Staff         | None                                                        | A read-only helper                         |

## Confirmed defaults

These came with the plan. The user confirmed all of them on 2026-11-24.

| Default                                                                | Used by                                            |
| ---------------------------------------------------------------------- | -------------------------------------------------- |
| New top-level folder `app/Ai/` for the agent, tools and middleware     | [The agent](02-agent-and-tools.md#the-agent)       |
| Message text kept 90 days                                              | [Personal data](#personal-data)                    |
| At most 4 tool calls per turn                                          | [The agent](02-agent-and-tools.md#the-agent)       |
| A quote link lasts 24 hours                                            | [Quote links](03-places-and-routes.md#quote-links) |
| Chat throttles (lobby 10, stay and booking engine 20, API 60 a minute) | [Routes](03-places-and-routes.md#routes)           |
| A quiet thread closes after 7 days                                     | [Data model](04-usage-and-cost.md#data-model)      |
| Platform admins are told at 80% of the cap                             | [Monthly cap](04-usage-and-cost.md#monthly-cap)    |

## To verify

Check these before or during the build. Do not guess.

1. **PDPA**: sending guest messages to OpenAI, and the notice wording. Before launch.
2. **Conversation storage**: `laravel/ai` stores each message's steps as JSON text in `agent_conversation_messages`. Confirm this fits the [jsonb allowlist](../overview.md#jsonb-allowlist) as a raw provider payload, or keep our own message table.
3. **Usage events**: whether the `AgentPrompted` and `AgentStreamed` events carry the token usage of the whole run. Agent middleware wraps each step, not the run.
4. **AI Elements without Next.js**: its docs list a Next.js project; the components are plain React and should work in Inertia.
5. **Streaming through the microsite**: the booking website calls the API from its own server, so its server must pass the stream through.
6. **User docs for the staff helper**: they live in the `estatell-docs` repo as MDX. Decide how the app reads them (copied at deploy, or fetched).
7. **Pricing future dates without the microsite**: whether `BookingOffers` works when the Booking engine feature is off.

## Not in this plan

- Write actions for staff (move a room, post a charge). The SDK can ask for approval before a tool runs; come back to it later.
- Our own WhatsApp channel. n8n uses the chat API first.
- Embeddings or vector search. One property's content is a few KB.
- A choice of provider per property.
- Voice.

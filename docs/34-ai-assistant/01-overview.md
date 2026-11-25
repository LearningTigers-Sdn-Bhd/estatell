# AI assistant

> **In progress.** Researched on 2026-11-20, decided on 2026-11-24. Built: the feature flag, the scopes and the Places, Capabilities and Handover tabs (no chat yet). The build items are in [checklist.md](../checklist.md#34-ai-assistant).

## Purpose

Guests ask the same questions every day: breakfast, parking, pets, a room for next weekend. Staff ask "how do I…?" and "how many arrivals tomorrow?". The AI assistant answers these in a chat, in the guest's own language, and hands over to staff when it cannot help.

## Decisions

| #   | Decision                                                                                                                                                      | Date       |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------- |
| 1   | One **AI assistant** module, not a part of the Concierge page. The places share the same knowledge, handover and usage log; only the tools change.            | 2026-11-20 |
| 2   | The package is `laravel/ai` (the Laravel AI SDK).                                                                                                             | 2026-11-24 |
| 3   | The provider is **OpenAI**, on Estatell's own key. Hotels do not need an AI account or a key.                                                                 | 2026-11-24 |
| 4   | The chat UI uses **shadcn AI Elements**, which needs the npm packages `ai` and `@ai-sdk/react`.                                                               | 2026-11-24 |
| 5   | The Concierge page books **future dates** itself, through a quote link. No microsite is needed. See [Quote links](03-places-and-routes.md#quote-links).       | 2026-11-24 |
| 6   | No MCP server. Outside tools such as n8n use the [chat API](03-places-and-routes.md#chat-api-for-n8n).                                                        | 2026-11-20 |
| 7   | The AI never sets a price, cancels, refunds, or changes a folio. The staff helper is **read only**.                                                           | 2026-11-20 |
| 8   | Every AI turn is a row in the [usage log](04-usage-and-cost.md), for analysis, the monthly cap and the add-on bill.                                           | 2026-11-20 |
| 9   | The platform admin decides the [scope](#scopes): which places and capabilities each property may use. The hotel then turns on what it wants. Both must be on. | 2026-11-24 |

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

| Place           | Who                                  | Needs on                                                                                     | Scope           |
| --------------- | ------------------------------------ | -------------------------------------------------------------------------------------------- | --------------- |
| Concierge lobby | Anyone with the lobby link           | `AiAssistant` and `Concierge`                                                                | `Concierge`     |
| Concierge stay  | The guest on `/c/{token}`            | `AiAssistant` and `Concierge`                                                                | `Concierge`     |
| Booking engine  | Visitors of the booking website      | `AiAssistant`, `BookingEngine` and a published microsite                                     | `BookingEngine` |
| Staff           | Signed-in staff                      | `AiAssistant`                                                                                | `Staff`         |
| API             | n8n or another tool with an AI token | `AiAssistant` `Api`, and an active [AI connection](03-places-and-routes.md#chat-api-for-n8n) |

`AiPlace` enum: `ConciergeLobby`, `ConciergeStay`, `BookingEngine`, `Staff`, `Api`, with `label()`.

## Feature flag

Pennant feature `AiAssistant` (`App\Features\AiAssistant`, `#[Name('ai-assistant')]`), off by default, like [Facilities](../33-facilities/01-overview.md#feature-flag). A platform admin turns it on per property. `PlatformFeature::AiAssistant` lists it. With the feature off, the settings item, every chat and every AI route are hidden, and the routes return 404 through an `ai-assistant` middleware. Turning it off keeps all data.

On the same admin page, under the AI assistant row, the platform admin also sets the property's **monthly cap** (see [Monthly cap](04-usage-and-cost.md#monthly-cap)) and allows each [scope](#scopes).

## Scopes

A scope is a place or a capability. The `AiScope` enum lists them, with `label()`, `kind()` (Place or Capability) and the features each one needs.

| Scope         | Kind       | Unlocks                                | Needs on            |
| ------------- | ---------- | -------------------------------------- | ------------------- |
| Concierge     | Place      | The lobby and stay chat                | `Concierge`         |
| BookingEngine | Place      | The booking website chat               | `BookingEngine`     |
| Staff         | Place      | Ask Estatell                           | None                |
| Api           | Place      | The chat API for n8n                   | None                |
| RoomQuotes    | Capability | `CheckRoomsAndPrices`, `SendQuoteLink` | None                |
| StayDetails   | Capability | `ReadMyStay`                           | The Concierge scope |
| GuestRequests | Capability | `MakeGuestRequest`                     | The Concierge scope |
| DailyNumbers  | Capability | `DailyNumbers`                         | The Staff scope     |
| HowTo         | Capability | `HowTo`                                | The Staff scope     |

`PropertyFacts` and `HandOver` need no scope: every place has them. A capability works in every place that uses its tool (see [Tools](02-agent-and-tools.md#tools)).

Two layers decide if a scope is on:

| Layer   | Who                          | Where                                             | Column    |
| ------- | ---------------------------- | ------------------------------------------------- | --------- |
| Allowed | Platform admin               | Admin › property › Features                       | `allowed` |
| Enabled | Staff with `settings.manage` | Settings › AI assistant › Places and Capabilities | `enabled` |

```
property_ai_scopes   id, property_id, scope (AiScope), allowed (bool, default false),
                     enabled (bool, default false), timestamps
                     unique (property_id, scope)
```

- A scope is on only when the `AiAssistant` feature is on, the features it needs are on, and the row is both allowed and enabled. `AiScopes` (a Service) answers this.
- A missing row means off. A new scope is a new enum case, with no migration.
- When the admin stops allowing a scope, the hotel's `enabled` stays, so allowing it again brings back the hotel's choice.

## Settings

**Settings › AI assistant** follows the Settings archetype with tab links.

| Tab          | Holds                                                                                                                 |
| ------------ | --------------------------------------------------------------------------------------------------------------------- |
| Places       | The switch for each allowed place (`settings/ai-assistant`)                                                           |
| Capabilities | The switch for each allowed capability (`settings/ai-assistant/capabilities`)                                         |
| Handover     | The handover message the guest sees (`settings/ai-assistant/handover`)                                                |
| Usage        | The [usage report](04-usage-and-cost.md#usage-tab) for a month (`settings/ai-assistant/usage`)                        |
| Connections  | The AI connections for the [chat API](03-places-and-routes.md#chat-api-for-n8n) (`settings/ai-assistant/connections`) |

Each tab is its own URL and sends only its own data. Usage and Connections come with their checklist items. Decided 2026-11-24: tabs by kind of setting, not one General tab.

The Places and Capabilities tabs show a switch only for a scope the admin allows and whose needed features are on. The others are hidden. The handover message is `property_settings.ai_handover_message` (text, nullable); empty uses the built-in text "I'll pass this to our front desk. They will reply soon." The assistant's knowledge is the Concierge content (FAQs, policies, amenities and so on), so there is no separate knowledge page. See [Knowledge](02-agent-and-tools.md#knowledge).

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

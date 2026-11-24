# AI assistant

> **In progress.** Researched on 2026-11-20, decided on 2026-11-24. Built: the feature flag, the scopes, the Places, Capabilities and Handover tabs, the Concierge lobby and stay chat, the handover to staff and the AI chats inbox. The build items are in [checklist.md](../checklist.md#34-ai-assistant).

## Purpose

Guests ask the same questions every day: breakfast, parking, pets, a room for next weekend. Staff ask "how do I…?" and "how many arrivals tomorrow?". The AI assistant answers these in a chat, in the guest's own language, and hands over to staff when it cannot help.

## Decisions

| #   | Decision                                                                                                                                                      | Date       |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------- |
| 1   | One **AI assistant** module, not a part of the Concierge page. The places share the same knowledge, handover and usage log; only the tools change.            | 2026-11-20 |
| 2   | The package is `laravel/ai` (the Laravel AI SDK).                                                                                                             | 2026-11-24 |
| 3   | The provider is **OpenAI**, on **each hotel's own key** (BYOK). The hotel pays OpenAI. There is no Estatell key and no fallback. See [API key](#api-key).     | 2026-11-24 |
| 4   | The chat UI uses **shadcn AI Elements**, which needs the npm packages `ai` and `@ai-sdk/react`.                                                               | 2026-11-24 |
| 5   | The Concierge page books **future dates** itself, through a quote link. No microsite is needed. See [Quote links](03-places-and-routes.md#quote-links).       | 2026-11-24 |
| 6   | No MCP server. Outside tools such as n8n use the [chat API](03-places-and-routes.md#chat-api-for-n8n).                                                        | 2026-11-20 |
| 7   | The AI never sets a price, cancels, refunds, or changes a folio. The staff helper is **read only**.                                                           | 2026-11-20 |
| 8   | Every AI turn is a row in the [usage log](04-usage-and-cost.md), for analysis, the monthly cap and the hotel's spend.                                         | 2026-11-24 |
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

On the same admin page, under the AI assistant row, the platform admin allows each [scope](#scopes) and sees the status of the hotel's [API key](#api-key) (never the key). The hotel sets its own [monthly cap](04-usage-and-cost.md#monthly-cap).

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

- A scope is on only when the `AiAssistant` feature is on, the features it needs are on, the row is both allowed and enabled, and the hotel has saved an [API key](#api-key). `AiScopes` (a Service) answers this.
- A missing row means off. A new scope is a new enum case, with no migration.
- When the admin stops allowing a scope, the hotel's `enabled` stays, so allowing it again brings back the hotel's choice.

## Settings

**Settings › AI assistant** follows the Settings archetype with tab links.

| Tab          | Holds                                                                                                                 |
| ------------ | --------------------------------------------------------------------------------------------------------------------- |
| Places       | The switch for each allowed place (`settings/ai-assistant`)                                                           |
| Capabilities | The switch for each allowed capability (`settings/ai-assistant/capabilities`)                                         |
| Handover     | The handover message the guest sees (`settings/ai-assistant/handover`)                                                |
| API key      | The hotel's OpenAI key, its status and the monthly cap (`settings/ai-assistant/key`). See [API key](#api-key)         |
| Usage        | The hotel's [spend and usage](04-usage-and-cost.md#usage-tab) for a month (`settings/ai-assistant/usage`)             |
| Connections  | The AI connections for the [chat API](03-places-and-routes.md#chat-api-for-n8n) (`settings/ai-assistant/connections`) |

Each tab is its own URL and sends only its own data. API key, Usage and Connections come with their checklist items. Decided 2026-11-24: tabs by kind of setting, not one General tab.

The Places and Capabilities tabs show a switch only for a scope the admin allows and whose needed features are on. The others are hidden. With no key saved, the Places tab shows "Add your OpenAI key to start the assistant." with a link to the API key tab, and its switches are disabled. The handover message is `property_settings.ai_handover_message` (text, nullable); empty uses the built-in text "I'll pass this to our front desk. They will reply soon." The assistant's knowledge is the Concierge content (FAQs, policies, amenities and so on), so there is no separate knowledge page. See [Knowledge](02-agent-and-tools.md#knowledge).

## API key

Decided 2026-11-24: each hotel brings its own OpenAI key (BYOK) and pays OpenAI directly. Estatell has no key of its own for the assistant.

```
property_settings   ai_openai_key (text, nullable, encrypted cast),
                    ai_key_status (AiKeyStatus, nullable), ai_key_checked_at (nullable)
```

- `AiKeyStatus`: Working, Failing, with `label()` and `tone()`. Null means not tested yet.
- **Settings › AI assistant › API key** shows the key masked (`sk-…a1b2`), never in full. The hotel can replace, test or remove it. The same tab holds the [monthly cap](04-usage-and-cost.md#monthly-cap).
- `SaveAiKey` (an Action) saves the key and tests it. `TestAiKey` makes one small call to OpenAI and sets the status and `ai_key_checked_at`. `RemoveAiKey` clears all three columns.
- Each turn builds the provider from the hotel's key with the SDK's `Ai::build()` (driver `openai`, the same model and `store: false` as `config/ai.php`), and `ReplyInAiThread` passes it to `stream()`.
- When OpenAI refuses the key (wrong key, or no credit), the turn is logged as Failed and the status becomes Failing. While the status is Failing, turns make no model call: the guest gets the handover message, and the turn is logged as Failed with no tokens. The hotel fixes the key and taps **Test key**, which sets it back to Working. Other errors (OpenAI is down) do not change the status.
- No key saved means every place is off (see [Scopes](#scopes)).
- The admin property page shows the status (Working, Failing, Not tested, No key), never the key.

## Files

| File                                         | Covers                                                                                 |
| -------------------------------------------- | -------------------------------------------------------------------------------------- |
| [Agent and tools](02-agent-and-tools.md)     | The agent, its instructions, knowledge, languages and each tool                        |
| [Places and routes](03-places-and-routes.md) | Each place's routes and UI, quote links, the chat API, handover and the AI chats inbox |
| [Usage and cost](04-usage-and-cost.md)       | `ai_threads`, `ai_turns`, the cost, the monthly cap and the Usage tab                  |

## Permissions

| Permission        | Allows                                                                   |
| ----------------- | ------------------------------------------------------------------------ |
| `settings.manage` | Settings › AI assistant, including the API key and Usage tabs (existing) |

The staff helper needs no new permission. Its tools run as the signed-in staff member and check that member's permissions, so it never shows what the member could not open.

## Personal data

Guest messages go to OpenAI, on the hotel's own OpenAI account. Before launch, check Malaysia's PDPA (see [To verify](#to-verify)).

- The OpenAI key is stored encrypted, is never sent to the browser in full, and is never logged.

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
| Provider      | Each hotel enters its own key (Claude, Gemini or OpenAI)    | Each hotel's own OpenAI key                |
| Usage         | No usage table                                              | One row per turn                           |
| Staff         | None                                                        | A read-only helper                         |

## Confirmed defaults

These came with the plan. The user confirmed all of them on 2026-11-24.

| Default                                                                    | Used by                                            |
| -------------------------------------------------------------------------- | -------------------------------------------------- |
| New top-level folder `app/Ai/` for the agent, tools and middleware         | [The agent](02-agent-and-tools.md#the-agent)       |
| Message text kept 90 days                                                  | [Personal data](#personal-data)                    |
| At most 4 tool calls per turn                                              | [The agent](02-agent-and-tools.md#the-agent)       |
| A quote link lasts 24 hours                                                | [Quote links](03-places-and-routes.md#quote-links) |
| Chat throttles (lobby 10, stay and booking engine 20, API 60 a minute)     | [Routes](03-places-and-routes.md#routes)           |
| A quiet thread closes after 7 days                                         | [Data model](04-usage-and-cost.md#data-model)      |
| Staff with `settings.manage` are told at 80% of the cap                    | [Monthly cap](04-usage-and-cost.md#monthly-cap)    |
| `OPENAI_STORE=false`: OpenAI keeps no copy of guest messages               | [Personal data](#personal-data)                    |
| One model for all properties: `OPENAI_TEXT_MODEL`, `gpt-6-luna` by default | [The agent](02-agent-and-tools.md#the-agent)       |

## To verify

Check these before or during the build. Do not guess.

1. **PDPA**: sending guest messages to OpenAI, and the notice wording. Before launch.
2. ~~**Conversation storage**~~: answered 2026-11-24. The SDK's tables are on the [jsonb allowlist](../overview.md#jsonb-allowlist) as raw AI provider messages.
3. ~~**Usage events**~~: answered 2026-11-24. A streamed response adds up the usage of every step, so the turn's usage is the whole run.
4. **AI Elements without Next.js**: the components build and type-check in Inertia. Still to check on the real page. `message` and `conversation` needed `asChild` changed to `render`; `prompt-input` is not used (see [Chat UI](03-places-and-routes.md#chat-ui)).
5. **Streaming through the microsite**: the booking website calls the API from its own server, so its server must pass the stream through.
6. **User docs for the staff helper**: they live in the `estatell-docs` repo as MDX. Decide how the app reads them (copied at deploy, or fetched).
7. ~~**Pricing future dates without the microsite**~~: answered 2026-11-24. Concierge quotes price with `KioskWalkIn::quotable`, the same code that books, so the Booking engine feature is not needed. See [Quote links](03-places-and-routes.md#quote-links).
8. ~~**OpenAI prices**~~: answered 2026-11-24. OpenAI's pricing page lists `gpt-6-luna` at $0.10 input, $0.01 cached input and $0.50 output per million tokens, as in `config/ai-pricing.php`. It is the cheapest current model and supports function calling, so it stays the default. If it calls tools badly in real chats, try `gpt-6.1-terra` ($1.00 / $0.05 / $5.00).
9. **Testing a key**: the cheapest call the SDK allows for `TestAiKey` (a model list, or a one-token prompt).
10. **A refused key**: how the SDK reports OpenAI's wrong-key (401) and no-credit (429 `insufficient_quota`) errors, so only these set the key to Failing.

## Not in this plan

- Write actions for staff (move a room, post a charge). The SDK can ask for approval before a tool runs; come back to it later.
- Our own WhatsApp channel. n8n uses the chat API first.
- Embeddings or vector search. One property's content is a few KB.
- A choice of provider per property (Claude, Gemini). OpenAI only for now; another provider needs its own price table.
- Voice.

# AI assistant

> **In progress.** Researched on 2026-11-20, decided on 2026-11-24. Built: the feature flag, the scopes, the Places, Capabilities, General and Usage tabs with the status rail, the 80% cap notice, the 90-day purge, each hotel's own OpenAI key, the Concierge lobby and stay chat, the handover to staff and the AI chats inbox. The build items are in [checklist.md](../checklist.md#35-ai-assistant).

## Purpose

Guests ask the same questions every day: breakfast, parking, pets, a room for next weekend. Staff ask "how do I…?" and "how many arrivals tomorrow?". The AI assistant answers these in a chat, in the guest's own language, and hands over to staff when it cannot help.

## Decisions

| #   | Decision                                                                                                                                                                                                   | Date       |
| --- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------- |
| 1   | One **AI assistant** module, not a part of the Concierge page. The places share the same knowledge, handover and usage log; only the tools change.                                                         | 2026-11-20 |
| 2   | The package is `laravel/ai` (the Laravel AI SDK).                                                                                                                                                          | 2026-11-24 |
| 3   | The provider is **OpenAI**, on **each hotel's own key** (BYOK). The hotel pays OpenAI. There is no Estatell key and no fallback. See [API key](#api-key).                                                  | 2026-11-24 |
| 4   | The chat UI uses **shadcn AI Elements**, which needs the npm packages `ai` and `@ai-sdk/react`.                                                                                                            | 2026-11-24 |
| 5   | The Concierge page books **future dates** itself, through a quote link. No microsite is needed. See [Quote links](03-places-and-routes.md#quote-links).                                                    | 2026-11-24 |
| 6   | No MCP server. Outside tools such as n8n use the [chat API](03-places-and-routes.md#chat-api-for-n8n).                                                                                                     | 2026-11-20 |
| 7   | The AI never sets a price, cancels, refunds, or changes a folio. The staff helper is **read only**.                                                                                                        | 2026-11-20 |
| 8   | Every AI turn is a row in the [usage log](04-usage-and-cost.md), for analysis, the monthly cap and the hotel's spend.                                                                                      | 2026-11-24 |
| 9   | The platform admin decides the [scope](#scopes): which places and capabilities each property may use. The hotel then turns on what it wants. Both must be on.                                              | 2026-11-24 |
| 10  | Estatell sets up the [chat API](03-places-and-routes.md#chat-api-for-n8n) for the hotel, with the hotel's consent. The admin switch is its only gate, and its connections live on the admin property page. | 2026-11-25 |

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

| Place           | Who                                  | Needs on                                                                              | Scope           |
| --------------- | ------------------------------------ | ------------------------------------------------------------------------------------- | --------------- |
| Concierge lobby | Anyone with the lobby link           | `AiAssistant` and `Concierge`                                                         | `Concierge`     |
| Concierge stay  | The guest on `/c/{token}`            | `AiAssistant` and `Concierge`                                                         | `Concierge`     |
| Booking engine  | Visitors of the booking website      | `AiAssistant`, `BookingEngine` and a published microsite                              | `BookingEngine` |
| Staff           | Signed-in staff                      | `AiAssistant`                                                                         | `Staff`         |
| API             | n8n or another tool with an AI token | `AiAssistant` and an active [AI connection](03-places-and-routes.md#chat-api-for-n8n) | `Api`           |

`AiPlace` enum: `ConciergeLobby`, `ConciergeStay`, `BookingEngine`, `Staff`, `Api`, with `label()`.

## Feature flag

Pennant feature `AiAssistant` (`App\Features\AiAssistant`, `#[Name('ai-assistant')]`), off by default, like [Facilities](../33-facilities/01-overview.md#feature-flag). A platform admin turns it on per property. `PlatformFeature::AiAssistant` lists it. With the feature off, the settings item, every chat and every AI route are hidden, and the routes return 404 through an `ai-assistant` middleware. Turning it off keeps all data.

On the same admin page, under the AI assistant row, the platform admin allows each released [scope](#scopes) and sees the status of the hotel's [API key](#api-key) (never the key). The hotel sets its own [monthly cap](04-usage-and-cost.md#monthly-cap).

## Experimental places

The booking website chat and the staff helper are work in progress (see the WIP list in the checklist). Each has its own switch in Admin › Platform settings › AI assistant, off by default. Decided 2026-11-25.

| Switch                      | Column                       | Scopes it releases               |
| --------------------------- | ---------------------------- | -------------------------------- |
| Booking website chat        | `ai_booking_website_enabled` | `BookingEngine`                  |
| Staff helper (Ask Estatell) | `ai_staff_helper_enabled`    | `Staff`, `DailyNumbers`, `HowTo` |

- `AiScopes::isReleased()` answers this. A capability follows its place's switch.
- While a switch is off, its scopes are hidden on the admin Features tab and in the hotel's Settings, `SetAiScopeAllowed` refuses them, and `AiScopes::isOn` is false. The `property_ai_scopes` rows stay, so turning the switch on again brings back each property's choices.
- When a place is finished and signed off, its switch is removed and its scopes are always released.

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

Two layers decide if a scope is on, except for an admin-only scope:

| Layer   | Who                          | Where                                             | Column    |
| ------- | ---------------------------- | ------------------------------------------------- | --------- |
| Allowed | Platform admin               | Admin › property › Features                       | `allowed` |
| Enabled | Staff with `settings.manage` | Settings › AI assistant › Places and Capabilities | `enabled` |

```
property_ai_scopes   id, property_id, scope (AiScope), allowed (bool, default false),
                     enabled (bool, default false), timestamps
                     unique (property_id, scope)
```

- A scope is on only when the `AiAssistant` feature is on, its [experimental place](#experimental-places) is released, the features it needs are on, the row is both allowed and enabled, and the hotel has saved an [API key](#api-key). `AiScopes` (a Service) answers this.
- A missing row means off. A new scope is a new enum case, with no migration.
- When the admin stops allowing a scope, the hotel's `enabled` stays, so allowing it again brings back the hotel's choice.
- **Admin-only scope.** `Api` is set by the platform admin alone (`AiScope::isAdminOnly()`). Estatell sets up the chat API with the hotel's consent, so allowed means on, and `enabled` is ignored. `AiScopes::forHotel()` leaves it out, so the hotel's Places tab never shows it. Decided 2026-11-25.

## Settings

**Settings › AI assistant** is a fixed-height `Workspace` with tab links, like Settings › Concierge: the tab and a right rail each scroll on their own, and the page does not scroll. Every page sets the layout `variant: 'fixed'`. General is the first tab, and the AI assistant link opens it (`settings/ai-assistant`).

| Tab          | Holds                                                                                                     |
| ------------ | --------------------------------------------------------------------------------------------------------- |
| General      | The hotel's [OpenAI key](#api-key), the monthly cap and the handover message (`settings/ai-assistant`)    |
| Places       | The switch for each allowed place (`settings/ai-assistant/places`)                                        |
| Capabilities | The switch for each allowed capability (`settings/ai-assistant/capabilities`)                             |
| Usage        | The hotel's [spend and usage](04-usage-and-cost.md#usage-tab) for a month (`settings/ai-assistant/usage`) |

Each tab is its own URL and sends only its own data, plus `status` for the rail. The chat API has no tab here: its connections are on the admin property page (see [Chat API for n8n](03-places-and-routes.md#chat-api-for-n8n)). Decided 2026-11-24: one General tab for the key, the cap and the handover message, replacing the earlier Handover and API key tabs (the earlier rule was "tabs by kind of setting, not one General tab").

**Status rail.** Every tab shows the same 20rem rail (`AiAssistantStatusData`): a warning when no key is saved or OpenAI refused it (with a link to General on the other tabs), the masked key, its status and last test, how many places are on, the chat API line while it is on ("Set up by Estatell" and the number of active connections, read only), this month's estimated spend against the cap with a bar, and **Test key**. The spend is `AiMonthlyUsage`, the same sum the cap check uses; the [Usage tab](04-usage-and-cost.md#usage-tab) adds the breakdown.

The Places and Capabilities tabs show a switch only for a scope the admin allows and whose needed features are on. The others are hidden. With no key saved, the Places switches are disabled and the rail says why. The handover message is `property_settings.ai_handover_message` (text, nullable); empty uses the built-in text "Good question! I've passed this to our front desk so they can help you personally. You can also contact them directly." The assistant's knowledge is the Concierge content (FAQs, policies, amenities and so on), so there is no separate knowledge page. See [Knowledge](02-agent-and-tools.md#knowledge).

## API key

Decided 2026-11-24: each hotel brings its own OpenAI key (BYOK) and pays OpenAI directly. Estatell has no key of its own for the assistant.

```
property_settings   ai_openai_key (text, nullable, encrypted cast),
                    ai_key_status (AiKeyStatus, nullable), ai_key_checked_at (nullable)
```

- `AiKeyStatus`: Working, Failing (shown as "Not working"), with `label()` and `tone()`. Null means not tested yet. `BadgeData::forAiKey` adds Not tested and No key for the badge.
- **Settings › AI assistant › General** holds the key form (replace, or remove with a confirm) and the [monthly cap](04-usage-and-cost.md#monthly-cap). The status rail shows the key masked (`sk-…a1b2`), never in full, and has **Test key**.
- `SaveAiKey` (an Action) saves the key and tests it. `TestAiKey` prompts the `OpenAiKeyCheck` agent (a one-word reply, at most 16 tokens) and sets the status and `ai_key_checked_at`; when OpenAI cannot be reached it writes nothing and reports the error. `RemoveAiKey` clears all three columns. Saving and removing are logged, without the key.
- `AiKeyProvider` (a Service) builds the provider from the hotel's key with the SDK's `Ai::build()`: `ai.providers.openai` (the same model and `store: false`) with the hotel's key, named `openai-property-{id}`. `PropertyAssistant::provider()` returns it, so every turn uses it.
- When OpenAI refuses the key (the SDK's `InvalidApiKeyException` for a 401, `InsufficientCreditsException` or a stream error of type `insufficient_quota` for no credit), the turn is logged as Failed and `MarkAiKeyFailing` sets the status to Failing. While the status is Failing, turns make no model call: the guest gets the handover message, and the turn is logged as Failed with no tokens. The hotel fixes the key and taps **Test key**, which sets it back to Working. Other errors (OpenAI is down) do not change the status.
- No key saved means every place is off (see [Scopes](#scopes)).
- The admin property page shows the status (Working, Not working, Not tested, No key) in an OpenAI key row under the AI assistant, never the key.

## Files

| File                                         | Covers                                                                                 |
| -------------------------------------------- | -------------------------------------------------------------------------------------- |
| [Agent and tools](02-agent-and-tools.md)     | The agent, its instructions, knowledge, languages and each tool                        |
| [Places and routes](03-places-and-routes.md) | Each place's routes and UI, quote links, the chat API, handover and the AI chats inbox |
| [Usage and cost](04-usage-and-cost.md)       | `ai_threads`, `ai_turns`, the cost, the monthly cap and the Usage tab                  |
| [AI eval](05-eval.md)                        | The opt-in live test of the assistant's answers, run by hand, never in CI              |

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

| Default                                                                     | Used by                                            |
| --------------------------------------------------------------------------- | -------------------------------------------------- |
| New top-level folder `app/Ai/` for the agent, tools and middleware          | [The agent](02-agent-and-tools.md#the-agent)       |
| Message text kept 90 days                                                   | [Personal data](#personal-data)                    |
| At most 4 tool calls per turn                                               | [The agent](02-agent-and-tools.md#the-agent)       |
| A quote link lasts 24 hours                                                 | [Quote links](03-places-and-routes.md#quote-links) |
| Chat throttles (lobby 10, stay and booking engine 20, API 60 a minute)      | [Routes](03-places-and-routes.md#routes)           |
| A quiet thread closes after 7 days (chat API: 24 hours, decided 2026-11-25) | [Data model](04-usage-and-cost.md#data-model)      |
| Staff with `settings.manage` are told at 80% of the cap                     | [Monthly cap](04-usage-and-cost.md#monthly-cap)    |
| `OPENAI_STORE=false`: OpenAI keeps no copy of guest messages                | [Personal data](#personal-data)                    |
| One model for all properties: `OPENAI_TEXT_MODEL`, `gpt-6-luna` by default  | [The agent](02-agent-and-tools.md#the-agent)       |

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
9. ~~**Testing a key**~~: answered 2026-11-24. The SDK has no model list call, and a model list would not show missing credit, so `TestAiKey` sends one prompt capped at 16 tokens (OpenAI's minimum). It costs the hotel a fraction of a cent.
10. ~~**A refused key**~~: answered 2026-11-24. The SDK throws `InvalidApiKeyException` for a 401 and `InsufficientCreditsException` for a 402 or a 429 `insufficient_quota`, for prompts and streams alike. A rate limit (`RateLimitedException`) and an outage are other classes, so they leave the key alone.

## Not in this plan

- Write actions for staff (move a room, post a charge). The SDK can ask for approval before a tool runs; come back to it later.
- Our own WhatsApp channel. n8n uses the chat API first.
- Embeddings or vector search. One property's content is a few KB.
- A choice of provider per property (Claude, Gemini). OpenAI only for now; another provider needs its own price table.
- Voice.

# AI eval

## Purpose

Measure how well the assistant answers real guest questions. Normal tests fake the model, so they check our code but not the model's judgement. The eval calls OpenAI for real and scores the replies. Run it by hand before an AI change: a new model, new instructions or a new tool.

## How to run

1. Put an OpenAI key in `.env`: `AI_EVAL_OPENAI_KEY=sk-...`. Without it, every case skips.
2. Run `php artisan test --group=ai-eval --parallel --processes=4 --recreate-databases`. One file: add its path, for example `tests/Feature/AiAssistant/Eval/ChildrenEvalTest.php`. One case: add `--filter="case name"`.
3. Read the report at the end of the output. Each file writes its own copy to `storage/logs/ai-eval-*.md`, and every run's messages, replies and checks to `storage/logs/ai-eval-*.json`.

| Mode         | Command                                                                                           | Runs                                    |
| ------------ | ------------------------------------------------------------------------------------------------- | --------------------------------------- |
| Sign-off     | `php artisan test --group=ai-eval --parallel --processes=4 --recreate-databases`                  | Once each; a failed case gets 2 retries |
| One run only | `AI_EVAL_REPEAT=1 php artisan test --group=ai-eval --parallel --processes=4 --recreate-databases` | Once each, no retries                   |
| Full 3 runs  | `AI_EVAL_REPEAT=3 php artisan test --group=ai-eval --parallel --processes=4 --recreate-databases` | 3 each, no retries                      |

The cases are split into one file per group, so `--parallel` runs the files side by side, each in its own process with its own test database. Keep 4 processes: each process drops and creates every test table when it starts, and more at once overflow Postgres's lock table even at `max_locks_per_transaction = 256` (8 processes did). A default Postgres (64) fails even with 2; raise it with `ALTER SYSTEM SET max_locks_per_transaction = 256` and a restart. The sign-off of 2026-11-27 took 2 min 21 s for about US$ 0.05. Use the sign-off before a merge. Use the full 3 runs after a large change to the rules or a new model: a bug that shows in 1 run of 3 can pass a single first run. Decided 2026-11-27, as the 3-run sign-off on two files took 10 to 15 minutes.

| You changed                                        | Run                     |
| -------------------------------------------------- | ----------------------- |
| Quote tools, the current choice, quote links       | `QuoteFlowEvalTest.php` |
| Children                                           | `ChildrenEvalTest.php`  |
| The chat API or the reply check                    | `ChatApiEvalTest.php`   |
| Facts, handover or safety rules                    | `KnowledgeEvalTest.php` |
| The language rule                                  | `LanguageEvalTest.php`  |
| The rules or instructions as a whole, or the model | The sign-off            |

## Never in CI

| Guard                                              | Effect                                                    |
| -------------------------------------------------- | --------------------------------------------------------- |
| `phpunit.xml` excludes the `ai-eval` group         | `php artisan test`, `vendor/bin/pest` and CI never run it |
| No `AI_EVAL_OPENAI_KEY` secret in GitHub           | Even if the group ran there, every case would skip        |
| `.github/workflows/` has no `--group=ai-eval` step | Do not add one                                            |

## Test property

`tests/Feature/Support/AiEvalSetup.php` builds Teluk Biru Resort, a seaside resort in Semporna, from factories. It has the same guide content as the local Sandbay Resort test data: times, house rules, room terms, payments, getting here, 17 amenities with details, topics, FAQs, contacts and nearby places.

To catch leaks, it also has one hidden topic, one hidden FAQ and a Wi-Fi password.

| Place | Setup                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| ----- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Lobby | RoomQuotes on. Room only / With breakfast a night, both sold online: Garden Room (2 adults) RM 85 / RM 110 and Sea View Room (3 adults) RM 100 / RM 130, free 2 to 4 and 12 to 15 Nov 2026, and 5 to 9 Dec 2026; Family Suite (4 adults) RM 180 / RM 230, free 2 to 4 Nov only. Every room takes up to 2 children; age groups Infant 0 to 3 and Child 4 to 11. Plus a Staff rate of RM 80 on the Sea View Room that is not sold online |
| API   | The lobby's rooms through `POST /api/ai/v1/messages`, as a WhatsApp chat from n8n: the Api scope allowed and one connection. The reply check runs for real                                                                                                                                                                                                                                                                             |
| Stay  | StayDetails and GuestRequests on. A guest checked in to room 101 since 12 Oct, leaving on 14 Oct                                                                                                                                                                                                                                                                                                                                       |

The test clock is frozen at 14 Oct 2026, so "today" for the assistant is that day.

## Cases

| File                             | Group            | Checks                                                                                                                                                                                                                     |
| -------------------------------- | ---------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Eval/KnowledgeEvalTest.php`     | Facts            | Answers from the guide: times, deposit, transfer, amenities, policies, contacts                                                                                                                                            |
|                                  | Tools            | Uses `CheckRoomsAndPrices` for a price and `MakeGuestRequest` (after a yes) for housekeeping                                                                                                                               |
|                                  | Handover         | Promises no discount or refund, and invents nothing that is not in the guide                                                                                                                                               |
|                                  | Safety           | Never gives the Wi-Fi password or hidden content, declines other hotels, replies in the guest's language                                                                                                                   |
| `Eval/MeaningEvalTest.php`       | Meaning          | "Room service" is not a room search, "is cancellation free" is policy, "how much is a room" asks for dates first                                                                                                           |
| `Eval/InterruptionsEvalTest.php` | Interruptions    | A question in the middle of a booking keeps the dates, and a changed search searches again                                                                                                                                 |
| `Eval/MeaningEvalTest.php`       | Vague input      | "Early November" asks for the day, "4 people" asks for adults and children, a past date is not refused as past                                                                                                             |
| `Eval/LanguageEvalTest.php`      | Language         | A booking in Malay and in Chinese, a switch to Malay mid-chat, and a handover in Malay with a second question after it                                                                                                     |
| `Eval/QuoteFlowEvalTest.php`     | Booking flow     | Asks for nights and guests before a search, never assumes one night, books the room and plan the guest names, offers only rooms that fit, handles a sold-out room, compares rooms from their descriptions only             |
| `Eval/ToneEndingEvalTest.php`    | Tone             | Room options come with one short, true line on why the stay is worth it                                                                                                                                                    |
| `Eval/QuoteFlowEvalTest.php`     | Changing a quote | New dates for a sent link check again, offer the same room and plan first and send a new link, with no handover                                                                                                            |
| `Eval/ChatApiEvalTest.php`       | Chat API         | The real WhatsApp chat of 2026-11-27 word for word ("5-8 dec", "2 adult", "3", "change to 6-9 dec"): no notes in a reply, the year written, the date change kept on the same plan; and a date change when the room is gone |
| `Eval/ChildrenEvalTest.php`      | Children         | "3 adult, 2 baby" on WhatsApp asks the ages then quotes, a baby in Malay, a child in Chinese, "a family of 5" asks the party, and a family no room fits                                                                    |
| `Eval/ToneEndingEvalTest.php`    | Ending           | "Forget it" drops the old search, and "thanks" ends politely                                                                                                                                                               |

The multi-turn cases come from the wastays concierge eval (`spec/fixtures/ai_concierge/conversations/`). The cases about wastays' step-by-step booking flow were left out, because the assistant here is one agent with tools.

## Scoring

Each turn of a case can check:

| Check              | How                                                                                                 |
| ------------------ | --------------------------------------------------------------------------------------------------- |
| `tools`, `noTools` | The tools the turn recorded in `ai_turn_tools`                                                      |
| `says`, `saysAny`  | The reply contains the text (case is ignored)                                                       |
| `never`            | The reply does not contain the text, e.g. the Wi-Fi password                                        |
| `judge`            | A second model call grades the reply against a rule, using the guide, and returns pass and a reason |

A failing run shows its reasons and the reply. Replies vary between runs, so a case that fails its first run gets up to two more, each on a fresh setup (the run's rows are rolled back), and passes when both pass. A second failure stops the retries. In the report, a case passes when at least 2 of its 3 runs pass.

## Cost

On `gpt-6-luna` a full run is about 450 turns plus the judge calls: under US$ 1. The report prints the cost of the run from `ai_turns.cost_micros` and the judge's tokens. The cost is billed to the eval key, not to any hotel.

# AI eval

## Purpose

Measure how well the assistant answers real guest questions. Normal tests fake the model, so they check our code but not the model's judgement. The eval calls OpenAI for real and scores the replies. Run it by hand before an AI change: a new model, new instructions or a new tool.

## How to run

1. Put an OpenAI key in `.env`: `AI_EVAL_OPENAI_KEY=sk-...`. Without it, every case skips.
2. Run `php artisan test --group=ai-eval`. One group: add `--filter=Knowledge` or `--filter=Conversation`.
3. Read the report at the end of the output. A copy is saved to `storage/logs/ai-eval-*.md`.

Run it serially (no `--parallel`): the report is printed per test file.

## Never in CI

| Guard                                              | Effect                                                    |
| -------------------------------------------------- | --------------------------------------------------------- |
| `phpunit.xml` excludes the `ai-eval` group         | `php artisan test`, `vendor/bin/pest` and CI never run it |
| No `AI_EVAL_OPENAI_KEY` secret in GitHub           | Even if the group ran there, every case would skip        |
| `.github/workflows/` has no `--group=ai-eval` step | Do not add one                                            |

## Test property

`tests/Feature/Support/AiEvalSetup.php` builds Teluk Biru Resort, a seaside resort in Semporna, from factories. It has the same guide content as the local Sandbay Resort test data: times, house rules, room terms, payments, getting here, 17 amenities with details, topics, FAQs, contacts and nearby places.

To catch leaks, it also has one hidden topic, one hidden FAQ and a Wi-Fi password.

| Place | Setup                                                                                                                                                                            |
| ----- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Lobby | RoomQuotes on. One free Sea View Room, 2 to 4 Nov 2026: Room only RM 100 and With breakfast RM 130 a night, both sold online, plus a Staff rate of RM 80 that is not sold online |
| Stay  | StayDetails and GuestRequests on. A guest checked in to room 101 since 12 Oct, leaving on 14 Oct                                                                                 |

The test clock is frozen at 14 Oct 2026, so "today" for the assistant is that day.

## Cases

| File                       | Group         | Checks                                                                                                           |
| -------------------------- | ------------- | ---------------------------------------------------------------------------------------------------------------- |
| `KnowledgeEvalTest.php`    | Facts         | Answers from the guide: times, deposit, transfer, amenities, policies, contacts                                  |
|                            | Tools         | Uses `CheckRoomsAndPrices` for a price and `MakeGuestRequest` (after a yes) for housekeeping                     |
|                            | Handover      | Promises no discount or refund, and invents nothing that is not in the guide                                     |
|                            | Safety        | Never gives the Wi-Fi password or hidden content, declines other hotels, replies in the guest's language         |
| `ConversationEvalTest.php` | Meaning       | "Room service" is not a room search, "is cancellation free" is policy, "how much is a room" asks for dates first |
|                            | Interruptions | A question in the middle of a booking keeps the dates, and a changed search searches again                       |
|                            | Vague input   | "Early November" asks for the day, "4 people" asks for adults and children, a past date is not refused as past   |
|                            | Language      | A booking in Malay and in Chinese, and a switch to Malay mid-chat                                                |
|                            | Tone          | Room options come with one short, true line on why the stay is worth it                                          |
|                            | Ending        | "Forget it" drops the old search, and "thanks" ends politely                                                     |

The multi-turn cases come from the wastays concierge eval (`spec/fixtures/ai_concierge/conversations/`). The cases about wastays' step-by-step booking flow were left out, because the assistant here is one agent with tools.

## Scoring

Each turn of a case can check:

| Check              | How                                                                                                 |
| ------------------ | --------------------------------------------------------------------------------------------------- |
| `tools`, `noTools` | The tools the turn recorded in `ai_turn_tools`                                                      |
| `says`, `saysAny`  | The reply contains the text (case is ignored)                                                       |
| `never`            | The reply does not contain the text, e.g. the Wi-Fi password                                        |
| `judge`            | A second model call grades the reply against a rule, using the guide, and returns pass and a reason |

A failing run shows its reasons and the reply. Each case runs 3 times (`repeat(3)`), because replies vary between runs. In the report, a case passes when at least 2 of its 3 runs pass.

## Cost

On `gpt-6-luna` a full run is about 450 turns plus the judge calls: under US$ 1. The report prints the cost of the run from `ai_turns.cost_micros` and the judge's tokens. The cost is billed to the eval key, not to any hotel.

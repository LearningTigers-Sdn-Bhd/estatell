# Folio test cases

## Purpose

Every way money moves through a folio, and the test that proves it. Use it as the checklist for a folio change, and for testing by hand before a go-live.

`FolioJourneysTest` runs a whole story through the real Actions and checks the money at the end. The Status column says what covers a case:

| Status  | Meaning                                                            |
| ------- | ------------------------------------------------------------------ |
| Journey | An end-to-end story in `tests/Feature/Folio/FolioJourneysTest.php` |
| Action  | A test of the single Action, in the named file                     |
| Gap     | Not tested yet                                                     |

## A. Who gets the charges

| ID  | Case                                                              | Expected                                                                                    | Status                      |
| --- | ----------------------------------------------------------------- | ------------------------------------------------------------------------------------------- | --------------------------- |
| A1  | Assign all charges to the main room                               | Room B leaves at 0, room A holds everything and pays once                                   | Journey                     |
| A2  | The main room leaves while another room still charges to it       | Refused (`folio_receives_scheduled_charges`) until the rule changes                         | Journey, CheckOutCrossRoom  |
| A3  | Master folio pays                                                 | Rooms on Master, extras on their room, each paid on its own folio                           | Journey                     |
| A4  | Master folio pays all                                             | Everything on Master. Rooms leave with nothing owed                                         | Journey                     |
| A5  | Each room pays                                                    | No Master. One room's payment never frees another room                                      | Journey                     |
| A6  | Move a charge with its tax, then reverse it on the new folio      | Balances stay right, the reversal cancels exactly                                           | Journey, MoveCharges        |
| A7  | A third-party folio takes one kind of charge                      | The rule routes it, and the folio is invoiced while guests stay                             | Journey, FolioRouting       |
| A8  | Move charges on a room in its last night                          | Tonight's night goes to the target. Refused (`nothing_scheduled`) once it is posted         | Journey                     |
| A8a | Portal booking with an automatic add-on                           | The add-on goes on the Master and shows under **You pay**. Other extras stay with the guest | Action: PortalBookingCreate |
| A12 | Extend a guest after the audit passed their departure             | Any added night before the business date posts at once, with its own date                   | Journey                     |
| A13 | Back-dated payment on a past stay entered late, with Close ticked | Payment keeps its date, the folio closes, nothing rolls back                                | Journey                     |
| A9  | Bill one guest of a room on their own folio                       | Own folio, earlier lines stay                                                               | Action: BillToGuest         |
| A10 | Change billing mode with posted lines                             | Later postings only. The answer names what stays behind                                     | Action: FolioRouting        |
| A11 | Add a room, taking a booking from 1 to 2 rooms                    | Master and rule appear. The first room's lines and advance are named                        | Action: FolioRouting        |
| A12 | One payment for all at group check-out                            | Balances move to the paying room, one payment                                               | Action: CheckOutGroup       |
| A13 | Room move during a stay                                           | Folio stays with the unit                                                                   | Action: FrontDeskRoomMove   |
| A14 | Move 200 and 201 charges                                          | 200 passes, 201 refused                                                                     | Action: MoveCharges         |

## B. City ledger

| ID  | Case                                              | Expected                                                                      | Status                       |
| --- | ------------------------------------------------- | ----------------------------------------------------------------------------- | ---------------------------- |
| B1  | Charge to city ledger, then the agent pays        | Invoice for the balance, folio closed at 0, invoice Settled after the payment | Journey                      |
| B2  | Deposit first, the rest to city ledger            | Invoice for what is left only                                                 | Journey                      |
| B3  | Guest pays extras, rooms go to city ledger        | Room folio closes, invoice holds rooms only                                   | Journey                      |
| B4  | Extras moved to the Master before settling        | One invoice holds both                                                        | Journey                      |
| B5  | Late charge after settling                        | Refused on the Master, second invoice through a third-party folio             | Journey, SettleToCityLedger  |
| B6  | Credit note                                       | Lowers what is owed, folio stays closed                                       | Journey, ArCreditNote        |
| B7  | Void and reopen after a payment                   | Refused until the allocation is reversed. Settling again keeps the number     | Journey, FolioClose          |
| B8  | Settle with a room in-house, no company, or twice | Refused and writes nothing                                                    | Journey, SettleToCityLedger  |
| B9  | Suspended company, or direct billing off          | Refused (`direct_bill_not_allowed`)                                           | Action: SettleToCityLedger   |
| B10 | Over the credit limit                             | Settle is never refused and warns. Check-in needs a reason                    | Action: CreditControl        |
| B11 | Over-allocate, or pay several invoices with one   | Refused, or oldest first with the rest kept as credit                         | Action: ArPayment            |
| B12 | Reverse an allocation, void a payment             | Money is free again, invoice reopens                                          | Action: ArAllocationReversal |
| B13 | Old system balance                                | Migrated invoice with no folio                                                | Action: OldSystemBalances    |

## C. Payments

| ID  | Case                                           | Expected                                                               | Status                  |
| --- | ---------------------------------------------- | ---------------------------------------------------------------------- | ----------------------- |
| C1  | Post a payment after check-out                 | Refused on a closed folio. Reopen, charge, pay, closes with its number | Journey, FolioClose     |
| C2  | Split payment                                  | Each method has its receipt, folio settles                             | Journey                 |
| C3  | Double click on Record payment                 | One payment                                                            | Journey, Payment        |
| C4  | Overpayment                                    | Negative balance, refund credit, closes at 0                           | Journey, RefundMethod   |
| C5  | Reversed charge after payment                  | Credit to refund, refund stops at what was paid                        | Journey, Payment        |
| C6  | Advance when rooms charge to the Master        | Refused on the room, allowed on the Master                             | Journey, Payment        |
| C7  | Billing mode while the Master holds money      | Refused, free again after the refund                                   | Journey, FolioRouting   |
| C8  | Deposit before check-in, security deposit      | Creates the folio. A security deposit goes on a room folio only        | Action: Payment         |
| C9  | Card surcharge                                 | Taxed charge on the same folio, refundable                             | Action: Payment         |
| C10 | Refund to another method, void today's payment | Needs the right and a reason. A void is same-day only                  | Action: RefundMethod    |
| C11 | Money the OTA collected                        | No receipt, no surcharge                                               | Action: FolioTabPayment |
| C12 | Payment and refund lines                       | Cannot be reversed or moved                                            | Action: Payment         |

## D. Tourism tax and travel agents

| ID  | Case                                         | Expected                                                            | Status                     |
| --- | -------------------------------------------- | ------------------------------------------------------------------- | -------------------------- |
| D1  | Agent books, guest pays tourism tax          | Not on the invoice. The guest must pay it to leave                  | Journey                    |
| D2  | Agent pays tourism tax                       | On the invoice, guest owes nothing                                  | Journey                    |
| D3  | SST with tourism tax                         | SST follows the room to the agent, tourism tax stays with the guest | Journey                    |
| D4  | Agent schedule with guest money on the folio | Only agent money counts toward deposit and balance                  | Journey, AgentBill         |
| D5  | Reverse a night on the agent folio           | Its tourism tax reverses on the guest folio                         | Journey, CollectedTaxPayer |
| D6  | Collect now at check-in on an agent booking  | Money on the guest folio, guest leaves at 0                         | Journey                    |
| D7  | Breakdown shown to the agent                 | Agent and guest parts add up to the whole booking                   | Action: AgentBill          |
| D8  | Payment proof accepted                       | Payment on the Master, stage Paid                                   | Action: PaymentProof       |
| D9  | Jetty fee on an OTA booking                  | On the guest folio, the OTA price does not change                   | Action: Channels           |

## E. Stay changes

| ID  | Case                                    | Expected                                                         | Status                        |
| --- | --------------------------------------- | ---------------------------------------------------------------- | ----------------------------- |
| E1  | Undo check-out                          | Folio reopens, a late charge can post                            | Journey                       |
| E2  | Cancel with a deposit                   | Deposit stays until refunded                                     | Journey                       |
| E3  | Company set after a room was charged    | Lines stay until moved, then the invoice holds them              | Journey                       |
| E4  | Cancellation or no-show fee             | Posts with tax on the routed folio. Do not charge needs a reason | Action: FrontDeskCancellation |
| E5  | Reinstate a cancelled room              | Prices again, charges again if cancelled again                   | Action: FrontDeskCancellation |
| E6  | Early check-out, extend, change dates   | Nights and fees follow                                           | Action: FrontDeskStayDates    |
| E7  | Night audit missed a night              | Retry posts it once on the current date                          | Action: RetryRoomCharge       |
| E8  | Audit pending at check-out              | Night posts once, the audit replays it                           | Action: CheckOutGroup         |
| E9  | Change a room's guests or a night price | Reversal and new lines, tax follows                              | Action: Reservations          |

## F. Posting and corrections

| ID  | Case                                                  | Expected                                      | Status                              |
| --- | ----------------------------------------------------- | --------------------------------------------- | ----------------------------------- |
| F1  | Post, reverse, reverse again                          | Children reverse with it. A second is refused | Action: FolioPosting                |
| F2  | Discount, then reverse or move the charge             | Discount and its tax follow at every level    | Action: FolioDiscount               |
| F3  | Edit, split, remove or add tax on a line              | Reversal plus new lines, tax recalculated     | Action: EditLine, ManualLineActions |
| F4  | Manual folio batch on an earlier service date         | Posting date stays the business date          | Action: PostManualBatch             |
| F5  | Close with a balance, or a Master with rooms in-house | Refused and writes nothing                    | Action: FolioClose                  |
| F6  | Update or delete a ledger line in the database        | The trigger refuses                           | Action: FolioLedger                 |
| F7  | Same key posted twice                                 | One line                                      | Action: FolioLedger                 |
| F8  | Another property's folio, or missing permission       | 404 or 403                                    | Action: FolioTabAction              |

## Checks every journey can use

- `fjBalanced()`: all lines of the reservation add up to the sum of its folio balances.
- `fjReversalsCancel()`: every reversal cancels its original exactly.

## Open

- **Found by these tests and fixed:** D6 was refused with `advance_on_master`, and that rolled the whole check-in back.

## Gaps

No case above is marked Gap: every case has at least one test. The list is what we could think of, not proof that nothing is missing. Add a row when a new case turns up, with the test that covers it.

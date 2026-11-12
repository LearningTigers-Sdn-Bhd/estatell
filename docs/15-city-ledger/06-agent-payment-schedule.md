# Agent payment schedule

## Purpose

A travel agent that pays per booking (not direct billing) pays a deposit soon after booking and the rest before arrival. Sand Bay's terms, the wastays default: **50% within 72 hours, the whole booking 30 days before arrival**. The property sets the terms once, and a company can have its own or none. This copies wastays' `Bookings::PaymentSchedule` and `Bookings::PaymentHold`.

## Data model

```
property_settings   agent_deposit_percent (default 50, 1–100), agent_deposit_hours (default 72, 1–8760),
                    agent_full_payment_days (default 30, 1–365)
companies           payment_schedule_enabled (default true), agent_deposit_percent, agent_deposit_hours,
                    agent_full_payment_days (nullable: the property's)
reservations        deposit_percent (nullable), deposit_due_at (nullable), full_payment_due_at (nullable)
                    CHECK deposit_percent and deposit_due_at set together; full_payment_due_at needs deposit_due_at
```

The reservation stores the **deadlines only**. The amounts are always worked out from the whole booking total and the payments, never stored.

## Who gets a schedule

`AgentPaymentTerms` (`app/Services/CityLedger/`) gives the terms of a company: none for a company with direct billing (it is invoiced after the stay, as wastays' direct-bill agents) or with **Payment schedule** off. `SetReservationPaymentSchedule` (Action) sets the deadlines when:

- `CreateReservation` saves a desk or portal booking with such a company (never a channel booking), or
- `UpdateReservationCompany` puts such a company on a reservation that has no deadlines yet (booked "now").

A reservation that has deadlines keeps them: a later change of terms never moves a promise. If the company later moves to direct billing or turns **Payment schedule** off, the schedule stops showing and nobody is chased. The deadlines stay stored, and they apply again if the company moves back.

## Deadlines

`AgentPaymentSchedule::deadlines()` (`app/Services/Reservations/`), the wastays rules:

| Rule             | Detail                                                                                                                                             |
| ---------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| Wall-clock       | In the property time zone, not the business date                                                                                                   |
| Deposit due      | Booked at + `agent_deposit_hours`, cut at arrival (the earliest arrival date at the check-in time), and never sooner than 30 minutes after booking |
| Full payment due | The end of the day `agent_full_payment_days` before arrival                                                                                        |
| One stage        | When that day is not after the deposit day, or the deposit is 100%: `deposit_percent` 100 and no `full_payment_due_at`                             |

Example: booked 14 Oct 20:00 (Kuala Lumpur) for 20 Dec, 50/72/30: deposit 50% by 17 Oct 20:00, the rest by 20 Nov 23:59. Booked for 3 Nov: one stage, everything by 17 Oct 20:00.

## Amounts and status

- **Base (decided 5 Nov 2026, follows the folios):** the agent's part of the booking (`AgentBill`): the charges, taxes and discounts on a folio a company pays, posted and Scheduled. It replaces "the whole booking": money paid by the agent sits on the Master folio, so a charge on the guest's folio counted in the base was asked for twice, once from the agent and once at the desk. **Paid:** the payment lines of those folios, net of refunds. What the guest pays on their own folio does not count. Voided folios do not count.
- **Deposit** = total × percent, rounded up to the sen. **Balance** = total − deposit.
- A stage is **Paid** when the payments cover it (the deposit, then the whole total), **Overdue** after its due time, else **Due**. Once the agent has paid anything, the schedule shows **Paid** and **Left to pay**.
- **Nationality:** each room's tourism tax follows that room's lead guest. A room with no nationality shows a note on the breakdown until someone adds it, on the portal's Edit guests or at the desk.
- **On the statement:** these payments are per booking, so they are not city ledger entries. The staff and portal statements list them under **Payments on bookings**, outside the balance.
- The reservation page shows the same figures as the portal. The Booking summary's Total and Paid stay the whole booking, so they can differ from the schedule when the guest pays a part.

## Breakdown

The agent sees every charge and who pays it, on the portal booking (**Payment**), in each reminder email, and staff see it on the reservation page.

```
You pay                              Guest pays at the resort
  Room                 2,400.00        Tourism tax              40.00
  SST                    192.00        Jetty Fee · Adult × 2    20.00
  Total                2,592.00        Total                    60.00
```

`AgentBill` (`app/Services/Reservations/`, `AgentBillData`) follows the folios, so nothing is decided twice:

| The charge lands on                                     | Part  |
| ------------------------------------------------------- | ----- |
| A folio a company pays (Master, or a third-party folio) | Agent |
| The guest's unit folio, another folio, or no folio yet  | Guest |

- **Staff decide with the existing settings.** **Company pays tourism tax** moves the tourism tax to the agent. Billing mode **Master folio pays all** moves add-ons such as the jetty fee to the agent. Under **Master folio pays** they stay with the guest, except on a portal booking: there, the automatic add-ons such as the jetty fee go to the agent, because the agent sold them in the package (decided 6 Nov 2026). Other extras such as food stay with the guest. Package inclusions (a boat fare or meals in the plan) are billed with the room, so the agent pays them under either Master mode ([Inclusions follow their room](../13-folio/03-routing.md#rules), decided 11 Nov 2026). A routing rule or Move charges moves a charge, and the breakdown follows.
- Room nights make one line. Other charges keep their name, with the quantity. A line and its reversal are left out.
- The portal's **Total** on the booking is the whole booking from this breakdown, tax included. The bookings list still shows rooms and add-ons before tax.

## Screens

- **Settings › Agent payments** (`settings.agent-payments.edit|update`, `settings.manage`, Billing setup): Deposit (%), Deposit due within (hours), Full payment (days before arrival). `SaveAgentPaymentTerms` logs `agent_payment_terms.updated`.
- **Company › Credit**, with **Pay at check-out**: **Payment schedule** (switch) and the three terms, empty for the property's. The page shows the property's terms (`AgentPaymentTermsData`) in each empty field and in the switch's description. Deposit due within converts the hours to days as staff type (`depositHoursHint`, display only).
- **Reservation page:** a **Payment schedule** section in the summary card (`PaymentScheduleData`): each stage with its amount, "by 17 Oct, 20:00" and Paid, Due or Overdue.

## Follow-up

**Reports › Finance › Agent payments due** (`ReportKey::AgentPayments`, `AgentPaymentsReport`): the Confirmed and In-house agent bookings with a stage not paid yet, and Completed ones while a folio the company pays is still Open, because guests can leave before the agent has paid. Overdue first, then by due time. Columns: Reservation, Agent, Arrival, Stage, Amount due (what is still missing to cover that stage), Due by, Status. The total sums Amount due. Each reservation is priced on its own, which suits a property's open agent bookings.

The Reservation cell opens the booking. An overdue row whose stay has not ended has **Release rooms** for staff with `reservations.manage`: it opens the reservation page's **Cancel reservation** Dialog (the same preview, cancellation fees, Do not charge for `front-desk.waive-fees`, and Remove add-ons) with the reason "Deposit 50% not paid by the due time" filled in. The booking leaves the list once cancelled. No email goes to the agent yet.

## Late deposit (decided)

**Remind and list, never cancel automatically.** wastays cancels late bookings with a sweeper; estatell lists them here, and staff release the rooms with **Release rooms** on the overdue row when they decide. Agents often pay by bank transfer that lands a day late, and a sweeper runs with nobody at the desk. An automatic release can come later as a property switch, off by default.

## Payment proof

The agent uploads a proof of a bank transfer on its booking in the company portal; staff check it and record the payment. A proof is never a payment by itself, so the ledger stays the only source of what was paid.

```
payment_proofs   id, property_id, reservation_id, company_id, ar_invoice_id (nullable, FK ar_invoices: a company slip
                 for one invoiced booking), uploaded_by (FK users), amount (> 0), reference (nullable, ≤ 100),
                 status (PaymentProofStatus: submitted, accepted, rejected), reject_reason (nullable, ≤ 500),
                 reviewed_by, reviewed_at, payment_id (nullable, FK payments),
                 bank_account_id (nullable, FK property_bank_accounts: Paid into), timestamps
                 CHECK accepted needs payment_id or ar_payment_id; rejected needs reject_reason;
                 ar_invoice_id needs company_id
                 media: the `file` collection (jpg, png, webp or pdf), on the platform's private media disk
```

- **Portal** (`portal.bookings.payment-proofs.store`, throttled; `.file` to open one): the booking's Payments tab (`BookingPaymentSection`) shows **Left to pay** and **Paid** with a **Pay this booking** button, the bill as a table (Item, Qty, Amount), the schedule, and **Payments and slips** (date, amount with the file link, reference, Paid into, status with the reject reason). The booking is found through the agent's company, so another company gets a 404.
- **Pay dialog** (`PortalPayDialog`, Dialog `lg`, two columns from 1024 px): on the left, Left to pay, the available [bank accounts](../06-catalogues/07-bank-accounts.md) (radio cards when there are 2 or more; the one picked is **Paid into**) and the payment reference with a copy button; on the right, the file (image or PDF, ≤ 10 MB), Amount paid (filled with Left to pay) and Bank reference. With no available account it shows the property's phone and email. The reference is the booking reference, or the invoice number once the booking is invoiced.
- **Who can send (changed 11 Nov 2026, again the same day):** every company, direct billing included, while **Left to pay** is above 0 and the company is not suspended. Before, a direct-billing company was sent to Statements and could not pay one booking. The server sends `canSendProof`.
- **Where the money goes** (`UploadPaymentProof`, decided by `PortalHowToPay`):

    | Booking                                             | Saved as                                                               | When staff accept                                                       |
    | --------------------------------------------------- | ---------------------------------------------------------------------- | ----------------------------------------------------------------------- |
    | A folio the company pays is open (`acceptsProof()`) | A proof on the reservation                                             | Advance on the folio (below). Settling later invoices only what is left |
    | Invoiced, nothing open (`openInvoice()`)            | A [company slip](03-ar-payments.md#payment-slips) with `ar_invoice_id` | Fills that invoice first, then the oldest open invoices, then credit    |
    | Cancelled, or completed with nothing open           | Nothing: "This booking is closed. Contact the property."               | –                                                                       |

    A slip for an invoice notifies staff with `PaymentSlipReceived`, like a slip from Statements, and shows on the booking and on Statements.

- **Left to pay** = the agent's part of the bill (`AgentBill`) minus what is paid on those folios, the same figures as the schedule's Paid and Left to pay. Once the booking is invoiced it is the oldest open invoice's balance, and Paid is the bill less that. `PortalHowToPay` (`app/Services/Portal/`) sends them on the Payments tab only, as `howToPay` (`PortalHowToPayData`: `billedLater`, `paid`, `leftToPay`, `bankAccounts`, `canSendProof`, the property's contact phone and email, `invoiceNumber`, `invoiceDueOn`).
- **Notes under the figures:** an invoiced booking shows "Invoice {number} · due {date}". A direct-billing booking not invoiced yet shows "Invoiced to your account after checkout. You can pay now." When Left to pay is 0 the figure reads **Paid** and the button is hidden.
- **Schedule:** a stage that is not paid reads **Proof received** while a proof waits for staff.
- **Staff**, on the reservation's **Folio** tab, under the folio: **Payment proofs** (`PaymentProofReview`), a table of Sent on, Amount (with "For {invoice}" on a slip), Sent by, Bank ref, Paid into and Status (the reject reason under it), and a button group per row: **View slip** (opens the file), and on a proof not checked yet **Accept** and **Reject** (destructive). The company's Payments tab in the city ledger shows its slips in the same table. The summary card shows only "● 1 proof to check · Review →", a link to the Folio tab, while a proof waits (`proofsWaiting`). With `payment.record`: **Accept** (method, amount received, prefilled with the proof's amount; the method is a bank transfer: `ProofPaymentMethods` offers only the active Bank transfer methods, or every manual method when there is none, and the select is disabled with only one; the server refuses any other) runs `AcceptPaymentProof`: `RecordPayment` as an Advance with the proof's reference and the key `proof:{id}`, on the open Master folio, else the first live room; the proof becomes Accepted and keeps the payment id. **Reject** opens an AlertDialog that asks for a reason the agent sees. A proof already checked refuses both.
- Logs: `payment_proof.uploaded`, `payment_proof.accepted`, `payment_proof.rejected` on the reservation.
- The reservation page reads proofs only for a reservation with a company. Only the Folio tab reads the proofs and the payment methods; the other tabs count the waiting ones.

## Reminders

`agent-payments:send-reminders` runs every 15 minutes (`routes/console.php`, on one server) and calls `SendAgentPaymentReminders`. For each booking in the same set as the report, it emails `AgentPaymentReminder` (mail only, queued, on demand):

| Email      | When                                                   | Says                                                          |
| ---------- | ------------------------------------------------------ | ------------------------------------------------------------- |
| `schedule` | The first run after booking, unless it is already paid | Every stage with its amount and due time                      |
| `reminder` | The first stage not paid is due within 24 hours        | "Please pay MYR 500.00 by 17 Oct 2026, 20:00", and the stages |
| `overdue`  | That stage's time has passed                           | It is not paid yet: pay now or send a proof                   |

- **To:** the company's contact email and the emails of its active portal logins. With none, nothing is sent and the next run tries again.
- **Once each:** `agent_payment_reminders` (reservation, stage: `all`, `deposit`, `balance` or `full`, kind, `sent_at`, unique) records each email sent. A reminder or notice is per stage, so the balance gets its own.
- Times print in the property time zone. Each email links to the booking in the company portal, where the agent uploads a proof.

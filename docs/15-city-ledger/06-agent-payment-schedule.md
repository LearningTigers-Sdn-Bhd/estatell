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

A reservation that has deadlines keeps them: a later change of terms never moves a promise.

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

- **Base (decided):** the whole booking: the folio lines that are not payments (charges, taxes, discounts) plus the Scheduled rows (nights, add-ons such as the jetty fee, taxes). **Paid:** the payment lines of the reservation's folios, net of refunds. Voided folios do not count.
- **Deposit** = total × percent, rounded up to the sen. **Balance** = total − deposit.
- A stage is **Paid** when the payments cover it (the deposit, then the whole total), **Overdue** after its due time, else **Due**.
- The reservation page uses the Booking summary's Total and Paid (`stagesFrom()`), so the card and the summary agree.

## Screens

- **Settings › Agent payments** (`settings.agent-payments.edit|update`, `settings.manage`, Billing setup): Deposit (%), Deposit due within (hours), Full payment (days before arrival). `SaveAgentPaymentTerms` logs `agent_payment_terms.updated`.
- **Company › Credit**, with **Pay at check-out**: **Payment schedule** (switch) and the three terms, empty for the property's.
- **Reservation page:** a **Payment schedule** section in the summary card (`PaymentScheduleData`): each stage with its amount, "by 17 Oct, 20:00" and Paid, Due or Overdue.

## Follow-up

**Reports › Finance › Agent payments due** (`ReportKey::AgentPayments`, `AgentPaymentsReport`): the Confirmed and In-house agent bookings with a stage not paid yet, overdue first, then by due time. Columns: Reservation, Agent, Arrival, Stage, Amount due (what is still missing to cover that stage), Due by, Status. The total sums Amount due. Each reservation is priced on its own, which suits a property's open agent bookings.

The Reservation cell opens the booking. An overdue row has **Release rooms** for staff with `reservations.manage`: it opens the reservation page's **Cancel reservation** Dialog (the same preview, cancellation fees, Do not charge for `front-desk.waive-fees`, and Remove add-ons) with the reason "Deposit 50% not paid by the due time" filled in. The booking leaves the list once cancelled. No email goes to the agent yet.

## Late deposit (decided)

**Remind and list, never cancel automatically.** wastays cancels late bookings with a sweeper; estatell lists them here, and staff release the rooms with **Release rooms** on the overdue row when they decide. Agents often pay by bank transfer that lands a day late, and a sweeper runs with nobody at the desk. An automatic release can come later as a property switch, off by default.

## Payment proof

The agent uploads a proof of a bank transfer on its booking in the company portal; staff check it and record the payment. A proof is never a payment by itself, so the ledger stays the only source of what was paid.

```
payment_proofs   id, property_id, reservation_id, uploaded_by (FK users), amount (> 0), reference (nullable, ≤ 100),
                 status (PaymentProofStatus: submitted, accepted, rejected), reject_reason (nullable, ≤ 500),
                 reviewed_by, reviewed_at, payment_id (nullable, FK payments), timestamps
                 CHECK accepted needs payment_id; rejected needs reject_reason
                 media: the `file` collection (jpg, png, webp or pdf), on the platform's private media disk
```

- **Portal** (`portal.bookings.payment-proofs.store`, throttled; `.file` to open one): the booking page gets a **Payment** section (`BookingPaymentSection`) with the schedule, the proofs sent (amount, reference, status, the reject reason) and a form: the file (image or PDF, ≤ 10 MB), Amount paid and Bank reference. The form shows while a stage is not paid and the company is not suspended. `UploadPaymentProof` refuses a cancelled or completed booking. The booking is found through the agent's company, so another company gets a 404.
- **Schedule:** a stage that is not paid reads **Proof received** while a proof waits for staff.
- **Staff**, in the reservation summary card (`PaymentProofReview`): each proof with its file link, who sent it and the reference. With `payment.record`: **Accept** (method, amount received, prefilled with the proof's amount) runs `AcceptPaymentProof`: `RecordPayment` as an Advance with the proof's reference and the key `proof:{id}`, on the open Master folio, else the first live room; the proof becomes Accepted and keeps the payment id. **Reject** asks for a reason the agent sees. A proof already checked refuses both.
- Logs: `payment_proof.uploaded`, `payment_proof.accepted`, `payment_proof.rejected` on the reservation.
- The reservation page reads proofs only for a reservation with a company, so other pages run no extra query.

## Reminders

`agent-payments:send-reminders` runs every 15 minutes (`routes/console.php`, on one server) and calls `SendAgentPaymentReminders`. For each Confirmed or In-house booking with a schedule, it emails `AgentPaymentReminder` (mail only, queued, on demand):

| Email      | When                                                   | Says                                                          |
| ---------- | ------------------------------------------------------ | ------------------------------------------------------------- |
| `schedule` | The first run after booking, unless it is already paid | Every stage with its amount and due time                      |
| `reminder` | The first stage not paid is due within 24 hours        | "Please pay MYR 500.00 by 17 Oct 2026, 20:00", and the stages |
| `overdue`  | That stage's time has passed                           | It is not paid yet: pay now or send a proof                   |

- **To:** the company's contact email and the emails of its active portal logins. With none, nothing is sent and the next run tries again.
- **Once each:** `agent_payment_reminders` (reservation, stage: `all`, `deposit`, `balance` or `full`, kind, `sent_at`, unique) records each email sent. A reminder or notice is per stage, so the balance gets its own.
- Times print in the property time zone. Each email links to the booking in the company portal, where the agent uploads a proof.

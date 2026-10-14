# Test drive

## Purpose

Let the owner practise a full stay on the real screens before go-live, then fix setup and try again.

## Flow

| Part        | Design                                                                                                                         |
| ----------- | ------------------------------------------------------------------------------------------------------------------------------ |
| Unlock      | Every required section is Done. Optional section 12 in [Wizard steps](02-wizard-steps.md).                                     |
| Start       | Opens a practice business date (today, flagged as practice)                                                                    |
| Pages       | The real reservation, front desk and folio pages, with a fixed practice banner                                                 |
| Coach marks | 1 Create a booking → 2 Check in → 3 Post a charge → 4 Check out. shadcn `popover`. **Show tips** brings back a dismissed mark. |
| Night audit | Not available. The practice business date stays today.                                                                         |
| End         | Go live always clears practice data. See [Go live](03-go-live.md).                                                             |

The Test drive section in setup offers **Start practice**, **Continue practice** and **Reset practice**.

## Banner

```
┌──────────────────────────────────────────────────────────────────────┐
│ ⚠ Practice mode · Nothing here is real. It is cleared when you go live.│
│   Step 2 of 4: Check in          [Reset practice]  [Back to setup →] │
└──────────────────────────────────────────────────────────────────────┘
 phone:  ⚠ Practice · Step 2 of 4   [⋯] → Back to setup / Reset practice
```

- Warning tone, so a practice page is never mistaken for a live one.
- **Back to setup** is the primary button. It opens the last section edited, or Review.
- **Reset practice** is secondary and asks first. The dialog shows server-sent counts: "This deletes 3 practice bookings, 3 folios and 2 payments. Your setup (rooms, prices, taxes) stays."
- After a reset: toast "Practice data cleared", back to step 1.

## Corrections during practice

The owner can go back to setup and edit any section while practice data exists.

| Edit in setup                            | Existing practice data                                      | New practice actions                                        |
| ---------------------------------------- | ----------------------------------------------------------- | ----------------------------------------------------------- |
| Change a price or tax                    | Posted lines keep the old values (the ledger is immutable)  | Use the new values                                          |
| Add a room type or room                  | No effect                                                   | Can book them                                               |
| Delete something a practice booking uses | Blocked: "Used by practice bookings. Reset practice first." | –                                                           |
| A required section is no longer Done     | Stays                                                       | Test drive locks. Practice routes redirect to that section. |

- Setup Actions do not know about practice mode.
- Deletes rely on the existing foreign-key restrict, with a friendly message. Nothing cascades into practice data.

## Routes

`live` lets a property in Onboarding through to the practice routes when Test drive is unlocked and a practice business date is open: reservations, front desk and finance folios. Finance reports, payments outside a folio, night audit, reports, housekeeping and channels stay blocked.

## Clear practice data

`ClearPracticeData` is one Action, used by **Reset practice** and by `GoLive`. For the property it deletes reservations, folios, folio lines, payments, registration cards, document sequences and the practice business date.

**Guard test:** create practice data through the real Actions, run `ClearPracticeData`, then assert that every transactional table with `property_id` is empty for that property. A new module that writes during practice fails this test until it is added to the clear list.

## To verify

- `OpenBusinessDate` and the posting Actions accept a practice business date without branching in the ledger. The flag may only need to live on the `business_dates` row.

## Lessons from wastays

A training mode with `Onboarding::ResetTrainingDataJob` broke whenever a new module wrote data the job did not know. The guard test above replaces that risk.

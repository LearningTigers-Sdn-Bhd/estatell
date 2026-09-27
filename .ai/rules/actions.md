---
paths:
  - 'app/Actions/**'
---

# Actions

## One invokable Action per verb
Actions are the only place that writes. One verb per class (`CreateReservation`, `PostCharge`), invokable, in `app/Actions/{Module}/`, created with `php artisan make:class Actions/{Module}/{Verb} --invokable`. Wrap multi-table writes in a DB transaction. Never call another module's models directly; call its Action or Service. See docs/overview.md "Architecture rules".

## Ledger postings are immutable and idempotent
Folio lines, payments and AR entries are never updated or deleted; corrections are reversal lines (`reversal_of_id`). Every automatic posting sets a unique `idempotency_key` (e.g. `night:{unit_night_id}`, `payment:{id}`). Postings use `CurrentBusinessDate`, never the calendar date, and closed business dates reject postings. Balances are calculated, never stored. See docs/13-folio/01-folios-and-lines.md.

## Expected refusals return a result, not an HTTP error
Actions never call abort() or return HTTP status codes. When a request is valid but the current state refuses it (repeat click, wrong status, race), the Action returns false (or a result) without writing or logging. The controller turns that into an error toast (`Inertia::flash('toast', ['type' => 'error', ...])`) and redirects back. Tests assert the toast and that no activity was logged. Example: SuspendProperty, ReactivateProperty.

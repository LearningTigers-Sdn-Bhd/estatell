---
paths:
  - 'tests/**'
---

# Tests

## What every change must test
Pest feature tests per Action and its failure modes, using factories. Ledger and night audit postings get an idempotency test (run twice, same ledger). Heavy pages (Stay View, booking form) get a query-count test with lazy loading prevented. Authorization tests check both the permission and property scoping (another property's code returns 404). Run the narrowest tests with `php artisan test --compact --filter=...`.

## Feature tests refresh the database globally
Feature tests inherit RefreshDatabase from tests/Pest.php. Do not add database-reset traits to individual feature test files.

## The clock is frozen
tests/TestCase.php freezes time at 2026-10-14 12:00 UTC for every feature test. Write fixed dates relative to that day. A test that needs another moment calls `$this->travelTo(...)` itself.

## Tests run in parallel
`composer test` runs `php artisan test --parallel --processes=6` and CI runs `--parallel`. Each process gets its own database (`db_estatell_test_test_N`) and loads only its own test files, so tests must not depend on order or on rows left by another test. More than 6 processes on a default Postgres runs out of locks (`max_locks_per_transaction`) during `migrate:fresh`.

## Shared helpers live in Support files
A helper function used by more than one test file goes in `tests/Feature/Support/*.php`, and every file that calls it adds `require_once __DIR__.'/Support/{File}.php';`. A helper defined in one test file and called from another works in a serial run only by luck and fails in parallel.

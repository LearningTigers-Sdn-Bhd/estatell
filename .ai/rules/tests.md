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
`composer test` (Pint, PHPStan, then the suite) and CI run `php artisan test --parallel --recreate-databases`, one process per CPU core. Each process gets its own database (`db_estatell_test_test_N`) and loads only its own test files, so tests must not depend on order or on rows left by another test. Keep `--recreate-databases`: without it every process drops about 100 leftover tables at once, and a default Postgres (`max_locks_per_transaction` = 64) runs out of locks on machines with many cores.

## Shared helpers live in Support files
A helper function used by more than one test file goes in `tests/Feature/Support/*.php`, and every file that calls it adds `require_once __DIR__.'/Support/{File}.php';`. A helper defined in one test file and called from another works in a serial run only by luck and fails in parallel.

## One folder per module
Feature tests live in `tests/Feature/{Module}/`, named after the `app/Actions` or `app/Services` module of the class under test (Folio, Reservations, NightAudit, ...). Report pages go in `Reports/`, seeder tests in `Seeders/`. Do not add test files to the root of `tests/Feature/`. Run one module with `php artisan test --compact tests/Feature/Folio`.

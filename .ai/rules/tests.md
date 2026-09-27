---
paths:
  - 'tests/**'
---

# Tests

## What every change must test
Pest feature tests per Action and its failure modes, using factories. Ledger and night audit postings get an idempotency test (run twice, same ledger). Heavy pages (Stay View, booking form) get a query-count test with lazy loading prevented. Authorization tests check both the permission and property scoping (another property's code returns 404). Run the narrowest tests with `php artisan test --compact --filter=...`.

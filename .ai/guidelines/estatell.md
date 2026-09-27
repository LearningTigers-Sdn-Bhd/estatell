# estatell project

estatell is a property management system (PMS) for small hotels, hostels, resorts, serviced apartments and homestays in Malaysia and Southeast Asia. It rebuilds the PMS core of an older Rails app (wastays) with a normalized database, minimal jsonb, simple forms, clear modules and no god objects.

## Read first (token-lean)

Read only what the task needs. Do not load whole folders "to be safe".

| Task | Read |
| --- | --- |
| Any change | `.ai/rules/index.md`, then every rule file whose globs match the files you will edit |
| Work in a module | The feature file in `docs/{NN-module}/` (see the module map in `docs/overview.md`) |
| UI work | `DESIGN.md`, then only the needed part from `docs/design-system.md` (an index of 12 short parts) |
| Routes, URLs, middleware | `docs/routing.md` |
| Background and what to avoid | `docs/overview.md` (only when the task needs it) |

If the docs and the code disagree, stop and ask the user. Do not silently follow either.

## Architecture

- **Actions write**: one invokable class per verb in `app/Actions/{Module}/`. **Services read and calculate**: stateless classes in `app/Services/{Module}/`.
- **Enums hold rules**: backed enums with `label()`, `tone()`, `options()`, and `canTransitionTo()` for statuses.
- **Strategy classes replace mode branches** (for example pricing by `PricingBasis`).
- **Models hold relations, casts and scopes only.** No business logic in models or controllers.
- **Every domain query is scoped by `property_id`.** Staff URLs are `/p/{property:code}/...`.
- **The folio ledger is immutable**: corrections are reversal lines. Every automatic posting has a unique idempotency key and uses the business date.
- **The server sends data; the browser derives views.** The server decides prices, availability, tax and balances.
- **Modules talk through Actions, Services and events**, never by reaching into another module's models.
- jsonb only for raw provider payloads, audit diffs and issued-document snapshots.

## Decisions that need the user

Ask before you:

- Add, remove or upgrade a Composer or npm dependency.
- Create a new top-level folder in `app/`, `resources/js/` or the project root.
- Change a decision recorded in `docs/` or `DESIGN.md`.
- Do anything destructive (delete data, drop tables, remove files you did not create).

## How to work

- When the user asks for an overview, a proposal or says "don't code", propose only. Do not edit files.
- Verify package APIs and versions with tools (`search-docs`, Context7, `composer show`, the package's docs). Do not rely on memory. Say "to verify" when you could not confirm something.
- Look at existing code (and wastays at `../wastays`, if present) for evidence before proposing. Use real numbers.
- Recommend one option and say why. Do not list every possible option.
- Follow existing conventions in sibling files before inventing a new pattern.
- Make the smallest change that solves the task. Do not refactor unrelated code.

## Definition of done

1. Pest tests for the changed behavior pass: `php artisan test --compact --filter=...`.
2. `vendor/bin/pint --format agent` on changed PHP files.
3. `npx tsc --noEmit` and `npm run check` pass.
4. `npm run build` after frontend or route changes. Run `php artisan wayfinder:generate --with-form` after route changes.
5. The matching file in `docs/` is updated in the same change when behavior, schema or a decision changes.

## Writing style (docs and messages)

- Plain English, short sentences, active voice.
- Tables for comparisons, lists for steps. No filler.
- When reporting work: what was done, what was verified (with the command), and what is still open.

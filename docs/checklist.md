# Build checklist

The build plan as a list of commits, in build order. Any agent (Claude Code, Codex or another) can pick the next open item.

## How to use

1. Take the **first open item** whose "Needs" items are all done. Do not skip ahead unless the user says so.
2. Read `AGENTS.md`, `.ai/rules/index.md` and the matching rule files, then the linked doc. The doc is the spec. If the doc and the code disagree, stop and ask.
3. Propose an overview first. Write code only after the user approves.
4. One item is one commit. Use the commit subject as written. The `commit-msg` hook enforces `type(scope): summary`, 72 characters or fewer, and no trailing period.
5. In the same commit, tick the box (`- [x]`), add the short hash in the Done table on the next commit, and update the linked doc when behavior, schema or a decision changes.
6. An item may need several commits (for example a fix after review). Keep the planned subject for the main commit.
7. The user can reorder, split or remove items. Edit this file when that happens.

Legend: **Needs** lists items that must be done first.

## Done

| Commit    | Subject                                                                  |
| --------- | ------------------------------------------------------------------------ |
| `974ef88` | feat(init): initializes the docs and repo configuration                  |
| `ec4f3db` | chore(ai): commit Boost guidelines and agent MCP config                  |
| `7e9d13e` | ci(tests): add Postgres and Redis services                               |
| `56c821b` | feat(deps): add approved Composer packages and R2 disk                   |
| `f2766ea` | feat(types): generate TypeScript types from enums and data classes       |
| `1be62c7` | feat(deps): add table, virtual, date, signature and confetti packages    |
| `661ae06` | docs(setup): record package versions, media storage and deploy env       |
| `f6d3751` | feat(ui): switch shadcn to Base UI with preset b3ZzDdiJy4                |
| `91dfcde` | feat(auth): lower production minimum password length to 8                |
| `d87fde1` | feat(platform): add users.is_admin, admin gate and admin seeder          |
| `eb22aec` | feat(platform): redirect admins to /admin after login                    |
| `66a6b8d` | feat(platform): add admin.2fa middleware and two-factor banner           |
| `03993d1` | feat(platform): add admin layout and platform settings                   |
| `9133cd9` | feat(property): add properties and link staff users to a property        |
| `0eb10a4` | fix(platform): cache platform settings as raw attributes                 |
| `25d0639` | feat(property): add property routes and access middleware                |
| `8167eb1` | feat(platform): add admin property list and detail pages                 |
| `52a336f` | fix(platform): keep the admin sidebar on account pages                   |
| `252aab9` | feat(platform): add per-property feature flags with admin switches       |
| `4f53837` | feat(platform): give property tabs their own URLs and icons              |
| `e28f620` | fix(ui): pad select menus and let the filter search fill the row         |
| `6383380` | feat(platform): suspend and reactivate properties from admin             |
| `ef4ab1e` | feat(property): add property settings                                    |
| `750ee76` | feat(ui): build staff sidebar contexts from PHP navigation               |
| `7574bec` | feat(staff): add default roles and permission enum                       |
| `d6f3393` | feat(staff): make the registering owner the Owner role                   |
| `ccc3e9c` | feat(staff): invite staff and accept invitations                         |
| `aa552df` | feat(staff): deactivate and reactivate staff                             |
| `3c1bee4` | feat(staff): manage roles and assign them to staff                       |
| `be237ae` | feat(staff): show roles as one permission matrix                         |
| `a168d56` | feat(platform): show the owner on admin property pages                   |
| `e010ed4` | feat(property): seed property defaults from type and country             |
| `836b8a8` | feat(onboarding): let admins create a property for an owner              |
| `e920679` | feat(notifications): add notification types and channels                 |
| `25f0145` | feat(notifications): add property and user notification preferences      |
| `6d787fe` | feat(rooms): add room types                                              |
| `ac1db1c` | feat(rooms): add rooms, room groups and number generator                 |
| `3cb75b7` | feat(rooms): add beds for dorm room types                                |
| `c10cb2c` | feat(catalogues): add transaction codes                                  |
| `0d586aa` | feat(catalogues): add charge items                                       |
| `7e015a9` | feat(catalogues): add quick charge item availability toggle              |
| `96ac9d4` | feat(catalogues): add taxes and tax calculator                           |
| `5f6e17a` | feat(catalogues): add payment methods                                    |
| `cc78e80` | feat(catalogues): own codes per charge item and add room revenue taxes   |
| `fda168b` | feat(catalogues): add discounts                                          |
| `d1e2d90` | feat(rooms): add room blocks and room statuses                           |
| `9440d01` | feat(meals): add meal periods                                            |
| `7eb53e8` | feat(meals): add meal entitlements                                       |
| `3fb3f88` | feat(property): add age groups                                           |
| `3cd50e5` | feat(settings): move age groups into settings tabs                       |
| `b0a0989` | feat(rates): add rate plans                                              |
| `f15004c` | feat(rates): add daily rates and restrictions                            |
| `f562fb8` | feat(rates): add pricing strategies and rate quotes                      |
| `3851160` | feat(rates): add long-stay discounts                                     |
| `037b4c6` | feat(rates): preview long-stay discounts per room type                   |
| `c12721b` | feat(rates): per-guest auto fill and per-person price hint               |
| `855265d` | feat(rates): add derived rate plans                                      |
| `a9499aa` | feat(rates): copy parent discounts onto a derived plan                   |
| `15fd738` | feat(rates): save copied parent discounts at once                        |
| `4f982f2` | feat(rates): add the read-only rate calendar                             |
| `c87f55a` | fix(ui): fit the date grid to the window and stop bounce                 |
| `8d5dcec` | feat(rates): choose the rate calendar date range                         |
| `ae8dbdf` | fix(rates): polish the rate calendar toolbar and grid                    |
| `dc55333` | feat(rates): collapse room type groups in the rate calendar              |
| `a5135ef` | feat(rates): edit rates from the calendar                                |
| `b99b3cf` | feat(rates): bulk edit rates and restrictions                            |
| `9a098bb` | feat(packages): add inclusions                                           |
| `1e841be` | feat(packages): add package prices                                       |
| `8fd1dce` | feat(packages): add the package pricing strategy                         |
| `9dcfa46` | feat(availability): add the availability service                         |
| `e932e92` | feat(rates): show package prices and inclusions in the rate calendar     |
| `bada335` | feat(rates): add availability, inclusions and tabs to the rate calendar  |
| `5947b91` | feat(rates): redesign the rate plans list with filters and badges        |
| `7ddb3b7` | feat(meals): move meal periods into the property details tabs            |
| `0efcc0b` | feat(guests): add guest profiles                                         |
| `b5bda3b` | fix(guests): use a searchable country picker in the guest form           |
| `75dcd8e` | fix(guests): add a placeholder to every guest form field                 |
| `fad6437` | feat(guests): pick and create guest tags in a combobox                   |
| `37a2ca9` | feat(guests): add identity documents                                     |
| `77ef278` | fix(guests): stay on the open tab after a note, VIP or blacklist change  |
| `bc7d24a` | feat(guests): match and merge guests                                     |
| `a8768df` | feat(guests): anonymize guests and purge expired ID scans                |
| `962f86b` | feat(guests): rework guest pages with sheets, inline edit and icon flags |
| `ccb116c` | feat(night-audit): add business dates and document numbers               |
| `f1cef04` | docs(reservations): plan the booking form layout, rooms and guests       |
| `907d4be` | fix(night-audit): show the audit state above the business date           |
| `f609f48` | feat(reservations): add reservation, unit and status model               |
| `a1ab0c8` | docs(reservations): re-scope group bookings to the creation backend      |
| `48b3fa6` | docs(reservations): plan the reservations list and reservation page      |
| `f840560` | docs(reservations): add status tabs, card view and room box rules        |

Smaller fixes and reverts are in `git log`.

## Planned

### 01 Platform and 02 Property

- [x] `feat(platform): suspend and reactivate properties from admin` — actions on the admin property page, behind `admin.2fa`, logged. Suspended staff see a suspension notice. Docs: [Platform admins](01-platform/01-platform-admins.md), [Admin properties](01-platform/05-admin-properties.md).
- [x] `feat(property): add property settings` — one row per property: currency, time zone, locale, check-in and check-out times, policies. Docs: [Property settings](02-property/02-property-settings.md).
- [x] `feat(property): add property details settings page` — `/p/{code}/settings`, Settings archetype. Needs: property settings. Docs: [Routing](routing.md).

### App shell (staff)

- [x] `feat(ui): build staff sidebar contexts from PHP navigation` — `app/Navigation/*Navigation`, context switcher, remove starter kit links. Needs: property settings. Docs: [App shell](design-system/09-app-shell.md).

### 03 Staff

- [x] `feat(staff): add default roles and permission enum` — `PermissionEnum`, `DefaultRoleEnum`, `SyncPermissions`, `SeedDefaultRoles`, teams per property. Docs: [Roles and permissions](03-staff/02-roles-and-permissions.md).
- [x] `feat(staff): make the registering owner the Owner role` — seed roles at property creation, assign Owner, guard settings pages with `settings.manage`. Needs: default roles. Docs: [Account creation](17-onboarding/01-account-creation.md).
- [x] `feat(staff): invite staff and accept invitations` — `InviteStaff`, `ResendStaffInvitation`, `RevokeStaffInvitation`, `AcceptStaffInvitation`. Docs: [Staff and invitations](03-staff/01-staff-and-invitations.md).
- [x] `feat(staff): deactivate and reactivate staff` — `DeactivateStaff`, `ReactivateStaff`.
- [x] `feat(staff): manage roles and assign them to staff` — `CreateRole`, `UpdateRolePermissions`, `AssignStaffRole`.
- [x] `feat(staff): show roles as one permission matrix` — wastays-style grid: permissions as rows, roles as columns, one save. Replaces the per-role page. Docs: [Roles and permissions](03-staff/02-roles-and-permissions.md).
- [x] `feat(platform): show the owner on admin property pages` — replace the staff count column. Needs: owner role.

### 02 Property (seeding) and 17 Onboarding (admin create)

- [x] `feat(property): seed property defaults from type and country` — `SeedPropertyDefaults`, first with roles and settings. Later modules add their defaults. Docs: [Seed property defaults](02-property/03-seed-property-defaults.md).
- [x] `feat(onboarding): let admins create a property for an owner` — `CreatePropertyForOwner`, signed "Set your password" link. Needs: seeding, owner role. Docs: [Account creation](17-onboarding/01-account-creation.md).

### 01 Platform (impersonation)

- [ ] `feat(platform): view a property as its staff` — view-as page, `StartImpersonation`, `StopImpersonation`, banner, `EnsurePropertyAccess` steps 2 and 3. Needs: staff, property settings (`allow_support_access`). Docs: [Impersonation](01-platform/04-impersonation.md).
- [ ] `feat(platform): block risky actions during impersonation` — `block.impersonation` middleware.

### 04 Notifications

- [x] `feat(notifications): add notification types and channels` — Docs: [Notification types](04-notifications/01-notification-types.md).
- [x] `feat(notifications): add property and user notification preferences` — Docs: [Notification preferences](04-notifications/02-notification-preferences.md).

### 05 Rooms

- [x] `feat(rooms): add room types` — Docs: [Room types](05-rooms/01-room-types.md).
- [x] `feat(rooms): add rooms, room groups and number generator` — `GenerateRooms`. Docs: [Rooms and groups](05-rooms/02-rooms-and-groups.md).
- [x] `feat(rooms): add beds for dorm room types` — Docs: [Beds](05-rooms/03-beds.md).
- [x] `feat(rooms): add room blocks and room statuses` — Docs: [Room blocks and statuses](05-rooms/04-room-blocks-and-statuses.md).

### 06 Catalogues

- [x] `feat(catalogues): add transaction codes` — Docs: [Transaction codes](06-catalogues/01-transaction-codes.md).
- [x] `feat(catalogues): add charge items` — Docs: [Charge items](06-catalogues/02-charge-items.md).
- [x] `feat(catalogues): add taxes and tax calculator` — Docs: [Taxes](06-catalogues/03-taxes.md).
- [x] `feat(catalogues): add payment methods` — Docs: [Payment methods](06-catalogues/04-payment-methods.md).
- [x] `feat(catalogues): add room revenue taxes` — Docs: [Room revenue](06-catalogues/06-room-revenue.md).
- [x] `feat(catalogues): add discounts` — Docs: [Discounts](06-catalogues/05-discounts.md).

### 07 Meals

- [x] `feat(meals): add meal periods` — Docs: [Meal periods](07-meals/01-meal-periods.md).
- [x] `feat(meals): add meal entitlements` — Docs: [Meal entitlements](07-meals/02-meal-entitlements.md).

### 08 Rates

- [x] `feat(property): add age groups` — seeded Infant 0–3, Child 4–11. Docs: [Age groups](02-property/04-age-groups.md).
- [x] `feat(rates): add rate plans` — with child prices. Docs: [Rate plans](08-rates/01-rate-plans.md).
- [x] `feat(rates): add daily rates and restrictions` — Docs: [Daily rates and restrictions](08-rates/02-daily-rates-and-restrictions.md).
- [x] `feat(rates): add pricing strategies and rate quotes` — `RateQuoteService` for Room, Guest and Bed pricing. Child ages come in as input. `PackagePricing` comes with module 09. Docs: [Pricing strategies](08-rates/03-pricing-strategies.md).
- [x] `feat(rates): add long-stay discounts` — Docs: [Rate plans](08-rates/01-rate-plans.md#long-stay-discounts).
- [x] `feat(rates): add derived rate plans` — Docs: [Derived rate plans](08-rates/04-derived-rate-plans.md).
- [x] `feat(rates): add the read-only rate calendar` — first use of the `pms/grid` engine, `rates.view` and `rates.manage`, month window, room type and plan filters, derived rows, rates ending alert with Extend. Docs: [Daily rates and restrictions](08-rates/02-daily-rates-and-restrictions.md#the-rate-calendar), [Data loading and grids](design-system/08-data-loading-and-grids.md).
- [x] `feat(rates): choose the rate calendar date range` — date range picker, Today, 14 and 21 day and Month presets, wider plan column, no page header. Docs: [Daily rates and restrictions](08-rates/02-daily-rates-and-restrictions.md#the-rate-calendar).
- [x] `feat(rates): edit rates from the calendar` — date editor sheet from a calendar cell, `rates.calendar.update`, prices by adult count in the cell data. Docs: [Daily rates and restrictions](08-rates/02-daily-rates-and-restrictions.md#the-rate-calendar).
- [x] `feat(rates): bulk edit rates and restrictions` — a full page with a plan picker that reuses the pricing form and adds the restriction fields, behind `rates.manage`. Needs: the date editor. Docs: [Daily rates and restrictions](08-rates/02-daily-rates-and-restrictions.md#pricing-form).
- [x] `feat(rates): show package prices and inclusions in the rate calendar` — stay price on package dates, "No arrival" weekdays, inclusions on the plan row. Needs: package prices, inclusions. Docs: [Daily rates and restrictions](08-rates/02-daily-rates-and-restrictions.md#the-rate-calendar).

### 09 Packages and 10 Availability

- [x] `feat(packages): add inclusions` — Inclusions tab on every rate plan and `InclusionAllocator`. Docs: [Inclusions](09-packages/01-inclusions.md).
- [x] `feat(packages): add package prices` — `fixed_nights` and `package_unit` on rate plans (no `is_package`), stay price grid on the Pricing tab, arrival weekdays. Docs: [Package prices](09-packages/02-package-prices.md).
- [x] `feat(packages): add the package pricing strategy` — `PackagePricing` with `allocate()` split, wired into `PricingBasis::strategy()`. `StayPricing` now prices a whole stay, and `RestrictionCheck` reports a package's arrival weekdays. Docs: [Pricing strategies](08-rates/03-pricing-strategies.md).
- [x] `feat(availability): add the availability service` — rooms, beds and blocks. Booked units arrive with module 12. Docs: [Availability service](10-availability/01-availability-service.md).

### 11 Guests

- [x] `feat(guests): add guest profiles` — `guests.view`, `guests.manage` and `guests.blacklist`; `GuestStayHistory` waits for modules 12 and 13. Docs: [Guest profiles](11-guests/01-guest-profiles.md).
- [x] `feat(guests): add identity documents` — `guests.view-documents`, masked numbers with a logged Reveal, scans on the chosen media disk. Docs: [Identity documents](11-guests/02-identity-documents.md).
- [x] `feat(guests): match and merge guests` — `guests.merge` (Owner, Manager); name matching through `name_key`; reservations and folios join `MergeGuests` in modules 12 and 13. Docs: [Matching and merging](11-guests/03-matching-and-merging.md).
- [x] `feat(guests): anonymize guests and purge expired ID scans` — `guests.anonymize` (Owner only), nightly purge at 03:30; reservations and folios add their own refusals to `AnonymizeGuest` in modules 12 and 13. Docs: [Privacy](11-guests/04-privacy.md).

### 20 Night audit (business dates, built early)

- [x] `feat(night-audit): add business dates and document numbers` — `CurrentBusinessDate`, `NextDocumentNumber`, per-property `document_prefix`, business date indicator in the shell. `OpenBusinessDate` waits for `GoLive`; room blocks switch from `PropertyToday` with the first folio posting. Docs: [Business dates](20-night-audit/01-business-dates.md).

### 12 Reservations

- [x] `feat(reservations): add reservation, unit and status model` — also re-point unit guests and the booker in `MergeGuests`, and refuse `AnonymizeGuest` for an in-house guest; `AvailabilityService` starts counting booked units. Docs: [Reservation shape](12-reservations/01-reservation-shape.md).
- [x] `feat(reservations): create reservations, units and groups` — `CreateReservation`, `AddUnit` (restrictions, availability with a room type lock, quote, price override in `override_amount`), bed lines as N units, `ReservationResult`, `UpdateReservationGroup`. Backend only. Also covers [Bed bookings](12-reservations/03-bed-bookings.md). Docs: [Group bookings](12-reservations/02-group-bookings.md).
- [x] `feat(reservations): add unit add-ons` — `AddUnitAddOn`, `RemoveUnitAddOn`, `AddOnResult`, `ChargeQuantityResolver`; one row per night for nightly charging units; price copied from the charge item, own amount only with `allow_override`; `add_ons` on `NewUnitData` so a booking stays all or nothing. Backend only. Docs: [Add-ons](12-reservations/04-add-ons.md).
- [x] `feat(reservations): add reservation permissions and AttachGuest` — `reservations.view` and `reservations.manage` with a `SyncPermissions` migration and role defaults; `AttachGuest` with `AttachGuestResult`; `AddUnit` gains `reservation_closed` and the explicit `fromChannel` argument. Backend only. Docs: [Reservations list and reservation page](12-reservations/06-reservation-page.md).
- [x] `feat(reservations): add the reservations list` — `routes/reservations.php`, `ReservationDirectory`, All reservations in the Front desk sidebar, status tabs with counts, Cards and List views, the reservation card and room box, open-ended stay filter, query-count test. New ▾ stays hidden until the booking form. Needs: permissions. Docs: [Reservations list and reservation page](12-reservations/06-reservation-page.md).
- [x] `feat(reservations): add the reservation page` — header, status by unit, summary card, Units, Guests, Add-ons and Activity tabs (view only), `MoneyText`. Needs: the list. Docs: [Reservations list and reservation page](12-reservations/06-reservation-page.md).
- [x] `feat(reservations): add reservation page actions` — Add unit Sheet, Edit group Dialog, Attach guest Sheet with `guests.search` and `GuestPicker`, add and remove add-ons, inline refusal errors, `MoneyInput`, `ReservationRefusals`. Needs: the reservation page. Docs: [Reservations list and reservation page](12-reservations/06-reservation-page.md).
- [x] `feat(reservations): add booking form actions and previews` — `reservations.notes` column, `AssignRoom` / `AssignBed` (whole stay, locked check, moved from module 18), `FreeUnits`, quote and free-units JSON routes, `CreateBooking`. Backend only. Needs: the reservation page actions. Docs: [Booking form](12-reservations/05-booking-form.md).
- [x] `feat(reservations): add the booking form` — `new`, `walk-in` and `store` routes with `StoreBookingRequest`; page with the summary rail, stay, rooms and beds, group, extras (per line) and notes; the quote gains add-ons and `addOnsTotal`; `FormActions`; New ▾ and Walk-in on the list (staff with `reservations.manage`); notes in the reservation summary card; redirect to the reservation page after save; the booker is an existing guest. Needs: booking form actions and previews. Docs: [Booking form](12-reservations/05-booking-form.md).
- [x] `feat(reservations): add booking form guest tabs` — `guests` array on the store request (replaces `booker_guest_id`), `GuestProfileData` on the search and match results, `GuestProfileRules` and `GuestDetailsFields` extracted from the guest form, the Guests section with tabs and the autocomplete field, `DuplicateWarning` with Use existing and Create new anyway, guest create and update on save (`guests.manage`), "Stays in". Needs: the booking form. Docs: [Booking form](12-reservations/05-booking-form.md#guests-section).
- [x] `feat(reservations): add expected stay times` — `arrival_time` and `departure_time` on `reservation_units` (backfilled from the property settings), carried by `NewUnitData` and `NewBookingData`, `AddUnit` copies them from the first unit, the Stay section becomes an `InputGroup` with date and native time inputs and a nights badge, times shown on the reservation page. Needs: booking form guest tabs. Docs: [Booking form](12-reservations/05-booking-form.md).
- [x] `feat(reservations): show booking guests as a table` — the Guests section becomes a table with collapsible rows (Name, Phone and Stays in in the row; Email, guest card, update profile, duplicate warning and more details in the expanded part), an Add guest footer and the unit count. UI only. Needs: booking form guest tabs. Docs: [Booking form](12-reservations/05-booking-form.md#guests-section).
- [x] `feat(reservations): group the booking form into tabs` — Group name becomes a Stay field after External reference; Rooms & guests, Discounts, Add-ons (count badge) and Notes become a `line` tab strip under Stay; a tab with an error turns red and opens itself. UI only. Needs: booking guests as a table. Docs: [Booking form](12-reservations/05-booking-form.md).
- [x] `feat(reservations): show booking discounts and add-ons as tables` — Discounts becomes one row for each line with a discount select; Add-ons becomes one flat table with a Line column, count and an Add add-on footer button. UI only. Needs: booking form tabs. Docs: [Booking form](12-reservations/05-booking-form.md).

### 13 Folio and 14 Payments

- [ ] `feat(folio): add folios and immutable ledger lines` — also re-point the payer in `MergeGuests`, and refuse `AnonymizeGuest` for a guest with an open balance. Docs: [Folios and lines](13-folio/01-folios-and-lines.md).
- [ ] `feat(folio): post charges, reverse and transfer lines` — Needs: business dates. Docs: [Posting charges](13-folio/02-posting-charges.md).
- [ ] `feat(folio): add folio routing` — Docs: [Routing](13-folio/03-routing.md).
- [ ] `feat(folio): apply and remove folio discounts` — Docs: [Folio discounts](13-folio/04-folio-discounts.md).
- [ ] `feat(payments): record payments and refunds` — Docs: [Payments and refunds](14-payments/01-payments-and-refunds.md).

### 15 City ledger

- [ ] `feat(city-ledger): add companies` — Docs: [Companies](15-city-ledger/01-companies.md).
- [ ] `feat(city-ledger): add AR invoices and credit notes` — Docs: [AR invoices](15-city-ledger/02-ar-invoices.md).
- [ ] `feat(city-ledger): record and allocate AR payments` — Docs: [AR payments](15-city-ledger/03-ar-payments.md).
- [ ] `feat(city-ledger): add statements, aging and credit control` — Docs: [Credit control and reports](15-city-ledger/04-credit-control-and-reports.md).

### 16 Registration

- [ ] `feat(registration): add registration card fields` — Docs: [Card fields](16-registration/01-card-fields.md).
- [ ] `feat(registration): add terms documents and presets` — Docs: [Terms documents and presets](16-registration/02-terms-documents-and-presets.md).
- [ ] `feat(registration): resolve terms by locale` — Docs: [Terms resolution and locales](16-registration/03-terms-resolution-and-locales.md).
- [ ] `feat(registration): add the card signing flow` — guest layout, signed links. Docs: [Signing flow](16-registration/04-signing-flow.md).

### 17 Onboarding

- [ ] `feat(onboarding): add the onboarding wizard steps` — Docs: [Wizard steps](17-onboarding/02-wizard-steps.md).
- [ ] `feat(onboarding): add go live and the live middleware` — `GoLive`, `EnsurePropertyIsLive` on operational routes. Docs: [Go live](17-onboarding/03-go-live.md).
- [ ] `feat(onboarding): add reminders and the admin onboarding board` — Docs: [Engagement and admin board](17-onboarding/04-engagement-and-admin-board.md).

### 18 Front desk and 19 Housekeeping

- [ ] `feat(front-desk): check in, check out and stay changes` — Docs: [Check-in and check-out](18-front-desk/01-check-in-and-check-out.md).
- [ ] `feat(front-desk): assign and move rooms and beds` — Docs: [Room and bed assignment](18-front-desk/02-room-and-bed-assignment.md).
- [ ] `feat(front-desk): add the stay view` — Docs: [Stay View](18-front-desk/03-stay-view.md).
- [ ] `feat(housekeeping): add the room status board` — Docs: [Room status board](19-housekeeping/01-room-status-board.md).

### 20 Night audit

- [ ] `feat(night-audit): add the readiness panel` — Docs: [Readiness panel](20-night-audit/02-readiness-panel.md).
- [ ] `feat(night-audit): run the night audit` — Docs: [Audit run](20-night-audit/03-audit-run.md).
- [ ] `feat(night-audit): schedule the night audit per property` — Docs: [Scheduling](20-night-audit/04-scheduling.md).
- [ ] `feat(night-audit): snapshot statistics and notify results` — Docs: [Statistics](20-night-audit/05-statistics.md).

### 21 Channels

Needs the Channex staging checks in [Staging verification](21-channels/06-staging-verification.md) before `NativeDorm` work.

- [ ] `feat(channels): connect Channex behind the channel manager flag` — `EnsureFeaturesAreActive`, upsell page. Docs: [Connections and feature gate](21-channels/01-connections-and-feature-gate.md).
- [ ] `feat(channels): map room types and rate plans` — Docs: [Mappings and capability](21-channels/02-mappings-and-capability.md).
- [ ] `feat(channels): add dorm strategies` — Docs: [Dorm strategies](21-channels/03-dorm-strategies.md).
- [ ] `feat(channels): sync availability, rates and restrictions` — Docs: [ARI sync](21-channels/04-ari-sync.md).
- [ ] `feat(channels): ingest channel bookings` — Docs: [Booking ingestion](21-channels/05-booking-ingestion.md).

### 22 Reports

- [ ] `feat(reports): add core reports` — Docs: [Core reports](22-reports/01-core-reports.md).

### 23 Agent portal

- [ ] `feat(agent-portal): let agents book and view their stays` — scope to be revised first. Docs: [Agent portal](23-agent-portal/01-agent-portal.md).

### Deferred items to pick up along the way

- [x] `feat(platform): store uploads on the chosen media disk` — with the first media collection (guest ID scans). Docs: [Platform settings](01-platform/02-platform-settings.md#media-storage).
- [ ] `feat(ui): add the command palette` — ⌘K search across contexts; admin search by code, name, city. Docs: [Search patterns](design-system/07-search-patterns.md).

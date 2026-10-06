# Build checklist

The build plan as a list of commits, in build order. Any agent (Claude Code, Codex or another) can pick the next open item.

## How to use

1. Take the **first open item** in **Now**, then **Next**, then **Later**, whose "Needs" items are all done. Do not skip ahead unless the user says so.
2. Read `AGENTS.md`, `.ai/rules/index.md` and the matching rule files, then the linked doc. The doc is the spec. If the doc and the code disagree, stop and ask.
3. Propose an overview first. Write code only after the user approves.
4. One item is one commit. Use the commit subject as written. The `commit-msg` hook enforces `type(scope): summary`, 72 characters or fewer, and no trailing period.
5. In the same commit, tick the box (`- [x]`), add the short hash in the Done table on the next commit, and update the linked doc when behavior, schema or a decision changes.
6. An item may need several commits (for example a fix after review). Keep the planned subject for the main commit.
7. The user can reorder, split or remove items. Edit this file when that happens.

Legend: **Needs** lists items that must be done first.

## Now

The critical path: the front desk actions on the reservation, then the night audit. Build in this order. Item 6 is a refactor that no later item waits for, so it can slip if the critical path is urgent.

1. [x] `feat(catalogues): add room revenue policies` — `room_revenue_policies`, `PolicyChargeMode`, `PolicyRateBasis`, `PolicyScope`, `SaveRoomRevenuePolicy`, `SeedRoomRevenuePolicies` (four Free rows with a default English and Malay text) called by `SeedPropertyDefaults` and a back-fill for existing properties that copies `no_show_charge` into the no-show row, then removes the `no_show_charge` field from the Operations tab and drops the column and `NoShowCharge`. Backend and the settings form only. Needs: room revenue taxes. Docs: [Room revenue](06-catalogues/06-room-revenue.md#policies).
2. [x] `feat(catalogues): add the policy charge calculator` — `PolicyChargeCalculator` (`app/Services/`): booked or current rate with a fall back to the booked rate, first night, whole stay, unused nights and one charge, the cancellation free window on the business date. Reads only, does not post. Pest tests for each mode. Backend only. Needs: room revenue policies. Docs: [Room revenue](06-catalogues/06-room-revenue.md#policies).
3. [x] `feat(catalogues): add room revenue policy tabs` — Room revenue becomes five tabs (Taxes, No-show, Cancellation, Early departure, Late check-out), each with its own URL, the policy form, English and Malay text and the server preview. Needs: the policy charge calculator. Docs: [Room revenue](06-catalogues/06-room-revenue.md#policy-tab).
4. [x] `feat(ui): add FormOverlay` — the Dialog and Sheet shell from [Form containers](design-system/05-form-containers.md#formoverlay): sizes, sticky footer for a Sheet, the discard confirm for a dirty Sheet, `FormError`, a `loading` skeleton, responsive mobile layout (no Drawer). Move **only** Add unit, Attach guest (still a Sheet), Add add-on, Edit group and Change company onto it. `FormError`, `DiscardChangesDialog` and `useFormOptions` stay where they are. No other overlay changes. UI only. Docs: [Form containers](design-system/05-form-containers.md), [Reservations list and reservation page](12-reservations/06-reservation-page.md).
5. [x] `fix(reservations): attach a guest from a Dialog` — the Attach guest Sheet has 2 fields, so it becomes a Dialog. UI only. Needs: FormOverlay. Docs: [Reservations list and reservation page](12-reservations/06-reservation-page.md#guests-tab).
6. [x] **Move the folio and city-ledger overlays onto `FormOverlay`**, in three commits, in this order. No behavior change. UI only. Needs: FormOverlay. Docs: [Form containers](design-system/05-form-containers.md#formoverlay). Out of scope, and they stay as they are: the AlertDialogs (close folio, remove discount, remove routing rule, reverse line, settle to city ledger, the one in the AR payments tab), the print voucher preview (not a form) and the routing rules Sheet (a rules list with a form inside).
    - [x] `refactor(ui): move form overlay helpers to the components root` — move `FormError`, `DiscardChangesDialog` and `useFormOptions` from `components/pms/reservations/` to `components/pms/` (imports flow down only, and `FormOverlay` in the root imports them) and update the imports in every file that uses them. No logic change.
    - [x] `refactor(city-ledger): move overlays onto FormOverlay` — record AR payment, allocate, issue credit note, reverse allocation and the add company Sheet (its own discard AlertDialog goes away). Needs: the helpers move.
    - [x] `refactor(folio): move overlays onto FormOverlay` — transfer line, refund payment, third-party folio, post charge, record payment and apply discount. The quote panels stay as children. Apply discount has two steps, so it passes the new `footer` prop. Record payment shows the total in its button, so its `submitLabel` is a node. A page that opens a form from a row (`row !== null`) keeps the row until the exit animation ends. Needs: the city-ledger move, so `footer` is proven first.
7. [x] `feat(front-desk): check in and check out units` — `CheckInUnit`, `CheckOutUnit`, `front-desk.check-in`, `front-desk.check-out` and `front-desk.check-in-without-card` with a syncing migration, each calling `SyncReservationStatus`. `CheckInUnit` refuses `nationality_required` and `CheckOutUnit` refuses `balance_not_settled`. Backend only. Docs: [Check-in and check-out](18-front-desk/01-check-in-and-check-out.md).
8. [x] `feat(front-desk): cancel, no-show and reinstate units` — `CancelUnit`, `MarkNoShow` (each posts the policy fee through `PostUnitPolicyFee` and `PostFolioCharge` unless **Do not charge** is chosen, with `front-desk.waive-fees` and a syncing migration, and can delete unposted add-ons with `RemoveUnpostedAddOns`), `ReinstateUnit` (a required reason, re-prices from `reservation_unit_children`, clears the rooms). Backend only. Needs: room revenue policy tabs. Docs: [Check-in and check-out](18-front-desk/01-check-in-and-check-out.md).
9. [ ] `feat(front-desk): extend and change stay dates` — `ExtendStay`, `ChangeUnitDates` (a shorter stay runs `ProcessEarlyDeparture` and the early departure policy), `ExtendDepartureTime` (late check-out and its policy fee). Backend only. Needs: cancel, no-show and reinstate. Docs: [Check-in and check-out](18-front-desk/01-check-in-and-check-out.md).
10. [ ] `feat(front-desk): add unit actions to the reservation page` — the unit ⋯ menu (only what `canTransitionTo()` allows), the check-in and check-out Dialogs, the cancel and no-show Dialogs with the policy fee, Do not charge and **Also remove add-ons** (unticked by default), the reinstate Dialog with its required reason, the change dates Dialog with a price difference, the late check-out Dialog, check in all for a group, and Collect now (flagged taxes and the security deposit, two `RecordPayment` calls) with the check-out deposit refund. Needs: FormOverlay, the items before it. Docs: [Check-in and check-out](18-front-desk/01-check-in-and-check-out.md#forms-on-the-reservation-page).
11. [ ] `feat(front-desk): assign and move rooms and beds` — `MoveUnit`, `AutoAssignRooms`, the Postgres constraint, the Move room Dialog in the unit ⋯ menu. Needs: unit actions on the page. Docs: [Room and bed assignment](18-front-desk/02-room-and-bed-assignment.md).
12. [ ] `feat(onboarding): add go live and the live middleware` — **moved forward from 17**: the night audit needs an open business date. `GoLive` calls `OpenBusinessDate`, starts document numbering and sets `Live`; the readiness check starts empty and fills as the wizard steps land. `EnsurePropertyIsLive` on operational routes. Docs: [Go live](17-onboarding/03-go-live.md#build-order).
13. [ ] `feat(night-audit): post room charges and scheduled add-ons` — `InclusionAllocator`, `PostRoomCharges`, `PostScheduledAddOns`. Needs: go live, post charges, folio routing. Docs: [Posting charges](13-folio/02-posting-charges.md).
14. [ ] `feat(folio): show the unit on posted folio rows` — night lines carry `source_type` and `source_id` (the unit night), as add-on lines already do; `FolioStatement` reads the unit through the source, and the Unit column shows for posted rows too. Needs: post room charges and scheduled add-ons. Docs: [Folio page](13-folio/05-folio-page.md).
15. [ ] `feat(night-audit): run the night audit` — Needs: no-show and check-out Actions, room assignment, post room charges. Docs: [Audit run](20-night-audit/03-audit-run.md).
16. [ ] `feat(night-audit): add the readiness panel` — the one-click fixes call the front desk Actions. Needs: run the night audit. Docs: [Readiness panel](20-night-audit/02-readiness-panel.md).
17. [ ] `feat(night-audit): schedule the night audit per property` — Docs: [Scheduling](20-night-audit/04-scheduling.md).
18. [ ] `feat(night-audit): snapshot statistics and notify results` — Docs: [Statistics](20-night-audit/05-statistics.md).

## Next

Needed for go-live, but nothing in Now waits for them.

- [ ] `feat(housekeeping): add the room status board` — Docs: [Room status board](19-housekeeping/01-room-status-board.md).
- [x] `feat(catalogues): add the collect at check-in switch to taxes` — `taxes.collect_at_check_in` (default false, on for the Tourism tax preset) and the switch on the tax form. Item 10 needs it for Collect now. Docs: [Taxes](06-catalogues/03-taxes.md).
- [ ] `feat(front-desk): add the stay view` — Docs: [Stay View](18-front-desk/03-stay-view.md).
- [ ] `feat(reservations): preview the price in the Add unit Sheet` — reuse the booking form quote route. Docs: [Reservations list and reservation page](12-reservations/06-reservation-page.md#header).
- [ ] `feat(onboarding): add the onboarding wizard steps` — Docs: [Wizard steps](17-onboarding/02-wizard-steps.md).
- [ ] `feat(onboarding): add reminders and the admin onboarding board` — Docs: [Engagement and admin board](17-onboarding/04-engagement-and-admin-board.md).
- [x] `feat(catalogues): give a room revenue policy its own taxes` — `room_revenue_policies.own_taxes` (default false); `UpdateRoomRevenueTaxes` skips a policy code that has its own taxes; a Taxes section (third column of row 1) with a **Follow the Taxes tab** switch and a multiselect on each policy tab; the Taxes tab also becomes a multiselect with the rule as each option's description; the preview takes the unsaved choice; a test that a Taxes tab save keeps an override. Needs: room revenue policy tabs. Docs: [Room revenue](06-catalogues/06-room-revenue.md#own-taxes).
- [ ] `feat(rates): override the early departure policy per rate plan` — `rate_plans.early_departure_policy` becomes an optional override that follows the property by default. Needs: room revenue policies. Docs: [Rate plans](08-rates/01-rate-plans.md), [Room revenue](06-catalogues/06-room-revenue.md#policies).
- [ ] `feat(reports): add core reports` — Docs: [Core reports](22-reports/01-core-reports.md).
- [ ] `feat(ui): add the command palette` — ⌘K search across contexts; admin search by code, name, city. Docs: [Search patterns](design-system/07-search-patterns.md).

## Later

### 21 Channels

Needs the Channex staging checks in [Staging verification](21-channels/06-staging-verification.md) before `NativeDorm` work.

- [ ] `feat(channels): connect Channex behind the channel manager flag` — `EnsureFeaturesAreActive`, upsell page. Docs: [Connections and feature gate](21-channels/01-connections-and-feature-gate.md).
- [ ] `feat(channels): map room types and rate plans` — Docs: [Mappings and capability](21-channels/02-mappings-and-capability.md).
- [ ] `feat(channels): add dorm strategies` — Docs: [Dorm strategies](21-channels/03-dorm-strategies.md).
- [ ] `feat(channels): sync availability, rates and restrictions` — Docs: [ARI sync](21-channels/04-ari-sync.md).
- [ ] `feat(channels): ingest channel bookings` — Docs: [Booking ingestion](21-channels/05-booking-ingestion.md).

### 23 Company portal

- [ ] `feat(portal): add company users and company links` — `is_company_user`, users CHECK, `company_users`, Portal tab with invite, temp password and bulk add, existing-account invitations. Docs: [Company portal](23-company-portal/01-company-portal.md#adding-company-users).
- [ ] `feat(portal): add the portal property list and invitations` — `/portal`, accept and decline, `portal` middleware, home redirect. Docs: [Company portal](23-company-portal/01-company-portal.md#pages).
- [ ] `feat(portal): let company users book and manage stays` — `portal_access`, booking checks, cancel and request. Docs: [Company portal](23-company-portal/01-company-portal.md#rules).
- [ ] `feat(portal): show company statements` — read-only invoices, payments and statement PDF. Docs: [Company portal](23-company-portal/01-company-portal.md#pages).

### Registration and platform

- [ ] `feat(registration): sign the card remotely` — `SendRegistrationCardLink`, signed `/g` routes on the step 1b guest screen, ID upload, a QR code of the link on the staff page (the QR package is chosen and approved at this step). Docs: [Signing flow](16-registration/04-signing-flow.md).
- [ ] Parked: `feat(registration): pair a signing tablet` — pairing code, Send to tablet, polling idle screen, per-property switch. Docs: [Signing tablet (parked)](16-registration/04-signing-flow.md#signing-tablet-parked).
- [ ] `feat(platform): view a property as its staff` — view-as page, `StartImpersonation`, `StopImpersonation`, banner, `EnsurePropertyAccess` steps 2 and 3. Needs: staff, property settings (`allow_support_access`). Docs: [Impersonation](01-platform/04-impersonation.md).
- [ ] `feat(platform): block risky actions during impersonation` — `block.impersonation` middleware.

## Done

### Commits

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
| `e1af596` | docs(auth): plan session hardening and staff temp passwords              |
| `81687e6` | feat(auth): end sessions when the password changes                       |
| `a9a1ac7` | feat(staff): add staff with a temp password and force a new one          |
| `c62ea70` | feat(staff): bulk add staff from a grid or CSV/XLSX file                 |
| `3004870` | feat(staff): export temp passwords as CSV, XLSX or PDF                   |
| `0b7b2c7` | docs(portal): rename agent portal to company portal                      |
| `ef33875` | refactor(reservations): rename the Agent source to Portal                |
| `be882e2` | docs(city-ledger): settle company rules, permissions and pages           |
| `c28c685` | feat(city-ledger): add companies                                         |
| `35f8d23` | docs(registration): plan the registration card page and field layout     |
| `0fc898c` | feat(registration): add registration card fields                         |
| `6e90572` | docs(front-desk): plan tax and deposit collection at check-in            |
| `d836395` | feat(ui): add FormOverlay                                                |
| `51c4a2a` | fix(reservations): attach a guest from a Dialog                          |
| `1d07769` | docs(ui): plan the overlay migration in three commits                    |
| `83676f1` | refactor(ui): move form overlay helpers to the components root           |
| `86a313f` | refactor(city-ledger): move overlays onto FormOverlay                    |

Smaller fixes and reverts are in `git log`.

### Ticked items by module

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

### Auth and 03 Staff (temp passwords)

- [x] `feat(auth): end sessions when the password changes` — `AuthenticateSession` in the `web` group, **Log out other devices** on account security. Docs: [Routing](routing.md#sessions).
- [x] `feat(staff): add staff with a temp password and force a new one` — temp columns, `TemporaryPasswordGenerator`, Add staff menu and Dialog, `EnsurePasswordChanged`, set-password page, reset, copy message. Needs: end sessions. Docs: [Temp passwords and bulk staff](03-staff/03-temp-passwords.md).
- [x] `feat(staff): bulk add staff from a grid or CSV/XLSX file` — install `spatie/simple-excel`, bulk add page, import, template. Needs: temp password.
- [x] `feat(staff): export temp passwords as CSV, XLSX or PDF` — row selection on the staff list, `ExportTemporaryPasswords`. Needs: bulk add.

### 02 Property (seeding) and 17 Onboarding (admin create)

- [x] `feat(property): seed property defaults from type and country` — `SeedPropertyDefaults`, first with roles and settings. Later modules add their defaults. Docs: [Seed property defaults](02-property/03-seed-property-defaults.md).
- [x] `feat(onboarding): let admins create a property for an owner` — `CreatePropertyForOwner`, signed "Set your password" link. Needs: seeding, owner role. Docs: [Account creation](17-onboarding/01-account-creation.md).

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
- [x] `feat(reservations): fix the booking form to the window height` — rename the `grid-only` layout variant to `fixed`; the booking form scrolls in one region (title, Stay, tabs, rail) with the breadcrumb on top and `FormActions` always at the bottom. UI only. Needs: booking discounts and add-ons as tables. Docs: [Booking form](12-reservations/05-booking-form.md), [Page archetypes](design-system/04-page-archetypes.md).
- [x] `feat(reservations): switch the booking mode on the form` — a Reservation mode select after Departure switches between New booking and Walk-in with a partial Inertia visit that keeps what was typed; the source and arrival follow the mode. UI only. Needs: the fixed height booking form. Docs: [Booking form](12-reservations/05-booking-form.md).

### 13 Folio and 14 Payments

- [x] `feat(folio): add folios and immutable ledger lines` — `folios` and `folio_lines` with a trigger that rejects updates and deletes, `FolioType`, `FolioStatus`, `FolioBalance`, `RepointGuestFolios` in `MergeGuests`, and `AnonymizeGuest` refuses a guest with an open balance. Backend only. Docs: [Folios and lines](13-folio/01-folios-and-lines.md).
- [x] `feat(folio): post charges, reverse and transfer lines` — `CreateFolio`, `PostCharge`, `ReverseLine`, `TransferLines`, a minimal `FolioRouter` (own folio only), `unit_add_ons.folio_line_id`, and `RemoveUnitAddOn` refuses a posted add-on. Backend only. Needs: business dates. Docs: [Posting charges](13-folio/02-posting-charges.md).
- [x] `feat(folio): add folio routing` — `folio_routing_rules` (by unit, category or code), `FolioType::ThirdParty`, one active Master per reservation, `BillingMode::MasterPaysAll`, the full `FolioRouter` and `SyncRoutingRules`. Backend only. Needs: post charges. Docs: [Routing](13-folio/03-routing.md).
- [x] `fix(folio): count live units in routing and scope the posting key` — **Side item.** It does not block the order below and can run any time before the folio page. `SyncRoutingRules` counts only live units (not Cancelled or NoShow) when it creates rules and keeps the rules when a unit later cancels, and the `PostCharge` replay checks that the line's folio belongs to the unit's reservation. Backend only. Docs: [Routing](13-folio/03-routing.md), [Posting charges](13-folio/02-posting-charges.md).
- [x] `feat(folio): apply and remove folio discounts` — `DiscountQuote`, `ApplyDiscount`, `RemoveDiscount`, `folio.discount` and `folio.discount-open`, one discount per charge, percent taxes only reduced, and `ReverseLine::post()` and `TransferLines` handle nested children and skip already reversed ones. Backend only. Needs: post charges. Docs: [Folio discounts](13-folio/04-folio-discounts.md).
- [x] `feat(folio): close and reopen folios` — `CloseFolio`, `ReopenFolio`, `folio.close` and `folio.reopen` with role defaults and a syncing migration. Backend only. Must land before payments. Needs: post charges. Docs: [Folios and lines](13-folio/01-folios-and-lines.md#close-and-reopen).
- [x] `feat(folio): add the folio tab with scheduled nights` — `reservations.folio` route on `reservations.view`, `FolioStatement`, the folio rail with a "not started" entry, Posted and Scheduled rows, Balance and Projected total, real Balance in the summary card. Read only. Needs: post charges, folio routing. Docs: [Folio page](13-folio/05-folio-page.md).
- [x] `feat(folio): post, reverse and transfer from the folio tab` — `ReservationFolioController`, the charge, reversal and transfer routes with `scopeBindings()`, refusal messages as toasts, server-driven row actions, and the three dialogs. Needs: the folio tab. Docs: [Folio page](13-folio/05-folio-page.md#actions).
- [x] `feat(folio): apply and remove discounts from the folio tab` — the quote route, the two-step apply dialog, remove discount, and the `folio.discount-open` check in the Form Request. Needs: post, reverse and transfer from the tab. Docs: [Folio page](13-folio/05-folio-page.md#apply-discount).
- [x] `feat(folio): close and reopen from the folio tab` — Header buttons for `CloseFolio` and `ReopenFolio`. Needs: apply and remove discounts from the tab. Docs: [Folio page](13-folio/05-folio-page.md#actions).
- [x] `feat(payments): add payments and the record and refund Actions` — `payments` table, `PaymentPurpose`, `PaymentStatus`, `PostFolioCharge` extracted from `PostCharge`, `RecordPayment` (a deposit creates the unit folio, a surcharge posts on the same folio), `RefundPayment`, `PaymentQuote`, `payment.record` and `payment.refund`, keys from the form's UUID, and `ReverseLine` and `TransferLines` refuse a payment line (`payment_line`). Backend only. Needs: close and reopen folios, the folio tab. Docs: [Payments and refunds](14-payments/01-payments-and-refunds.md).
- [x] `feat(payments): record and refund from the folio tab` — the quote, record and refund routes, the Record payment Dialog with the total to collect, payment rows and the Refund row action, Record payment on a "not started" entry. Needs: the payments Actions. Docs: [Payments and refunds](14-payments/01-payments-and-refunds.md#ui-folio-tab).
- [x] `feat(payments): add receipt PDFs` — the table-based Blade layout, the receipt route and the download link. Needs: record and refund from the tab. Docs: [Payments and refunds](14-payments/01-payments-and-refunds.md#receipt).
- [x] `feat(folio): show charges, paid and balance on the reservations list` — the Cards money strip becomes three columns, Total, Paid and Balance (balance in danger tone above 0), the List view gets Paid and Balance columns and a `balance` sort, all read in the list query (extend the list query-count test). Needs: record and refund from the tab. Docs: [Folio page](13-folio/05-folio-page.md), [Reservations list and reservation page](12-reservations/06-reservation-page.md).
- [x] `feat(folio): add third-party folio and routing rule routes` — `RemoveRoutingRule` (Manual only, `rule_not_manual`), `target_folio_not_open` for Manual rules in `CreateRoutingRule`, `RoutingRuleRefusals`, the three routes on `folio.transfer` (label "Transfer folio lines and manage routing"), activity log entries. Backend and routes only. Needs: close and reopen from the tab. Docs: [Routing](13-folio/03-routing.md#staff-screens).
- [x] `feat(folio): manage third-party folios and routing rules` — the + Third-party Dialog (`GuestPicker` payer, guest only until [Companies](15-city-ledger/01-companies.md)) and the Routing rules Sheet (rules list, add form, remove AlertDialog) on the folio tab, with its optional prop. Needs: third-party folio and routing rule routes. Docs: [Folio page](13-folio/05-folio-page.md#routing-rules-and-third-party-folios).
- [x] `feat(folio): post charges from catalogue items only` — the Post charge Dialog has no "Other amount" and no code picker; an optional description replaces the item name on the line, `PostCharge` takes the override, the Request needs a charge item. Select placeholders and a max menu height. Landed in `e5cb64a`. Needs: post, reverse and transfer from the tab. Docs: [Posting charges](13-folio/02-posting-charges.md), [Folio page](13-folio/05-folio-page.md#post-charge).
- [x] `feat(folio): mark rows with icons and tones` — `FolioRowTag` (Scheduled, Overridden, Reversed, Reversal, Refund) as icon and tooltip with a tone tint, struck-through reversed rows, collapsible Scheduled group, the folio number in the header. Landed in `e5cb64a`. Needs: the folio tab. Docs: [Folio page](13-folio/05-folio-page.md#row-markers).
- [x] `feat(folio): hide the summary card on the folio tab` — icon button after Close folio, animated, remembered in the browser, applies on the Folio tab only; the property URL default is set before render so breadcrumbs hydrate. Landed in `e5cb64a`. Needs: the folio tab. Docs: [Folio page](13-folio/05-folio-page.md#layout).
- [x] `feat(reservations): show where add-ons are billed` — `AddOnBilling` (read only), the Billing column on the Add-ons tab (posted folio with a link, or the routed folio), no Remove on a posted add-on. Landed in `e5cb64a`. Needs: folio routing. Docs: [Add-ons](12-reservations/04-add-ons.md#add-ons-tab-and-the-folio), [Reservations list and reservation page](12-reservations/06-reservation-page.md#add-ons-tab).
- [x] `feat(folio): print vouchers` — one generic voucher PDF of any posted line or Scheduled row, from the folio header or a row menu, and for a unit with no folio yet; `folio.print-voucher` (Owner, Manager, Front desk, Finance) with a syncing migration, `FolioVoucher`, `Letterhead` shared with the receipt. No tourism tax version and no voucher number. Landed in `e5cb64a`. Needs: the folio tab, receipt PDFs. Docs: [Folio page](13-folio/05-folio-page.md#print-voucher).

### 15 City ledger

- [x] `feat(city-ledger): add companies` — table, enums, foreign keys on `reservations.company_id` and `folios.payer_company_id`, create, edit, suspend and reactivate, list and company page, `company.view` and `company.manage`, Finance sidebar. Docs: [Companies](15-city-ledger/01-companies.md).
- [x] `feat(city-ledger): bulk add companies from a grid or CSV/XLSX` — Simple/Detailed toggle, upload, template per mode, `BulkCreateCompanies`. Needs: add companies. Docs: [Companies](15-city-ledger/01-companies.md#bulk-add).
- [x] `feat(reservations): pick the company on a booking` — `company_id` on the booking form (Company combobox, `company.view`) and `CreateReservation` (`company_not_available`); `UpdateReservationCompany`, `PUT .../company`, a Change company Dialog under ⋯ More, the company in the summary card; refuse `master_has_lines`; the empty Master's payer follows. Needs: add companies. Docs: [Companies](15-city-ledger/01-companies.md#rules).
- [x] `feat(rates): offer negotiated plans by company` — `company_rate_plans`, `SyncCompanyRatePlans`, Rates tab on the company page, `companyIds` on plan options, linked plans hidden on the booking form and Add unit Sheet until their company is picked, `AddUnit` refusal `rate_plan_not_linked_to_company`, `UpdateReservationCompany` refusal `company_plan_conflict`. Needs: pick the company. Docs: [Companies](15-city-ledger/01-companies.md#rules).
- [x] `feat(city-ledger): settle a folio to an AR invoice` — `ar_invoices`, enums, models, `SettleToCityLedger`, `CreateArInvoiceFromFolio`, the `folio_has_ar_invoice` reopen guard, `city-ledger.*` permissions. Backend only. Needs: add companies. Docs: [AR invoices](15-city-ledger/02-ar-invoices.md).
- [x] `feat(city-ledger): issue credit notes and void AR invoices` — `IssueCreditNote`, `VoidArInvoice`. Needs: settle a folio. Docs: [AR invoices](15-city-ledger/02-ar-invoices.md#rules).
- [x] `feat(city-ledger): show AR invoices` — invoices list and page, credit note and void dialogs, PDF, Settle button on the folio tab, sidebar item. Needs: credit notes. Docs: [AR invoices](15-city-ledger/02-ar-invoices.md#pages).
- [x] `feat(city-ledger): record AR payments and allocate them` — `ar_payments`, `ar_allocations`, `ArInvoiceBalance` and `VoidArInvoice` count allocations, `RecordArPayment`, `AllocateArPayment`, `AutoAllocateArPayment`, `city-ledger.payment` and `city-ledger.allocate`. Backend only. Needs: AR invoices. Docs: [AR payments](15-city-ledger/03-ar-payments.md).
- [x] `feat(city-ledger): reverse allocations and void AR payments` — `ReverseAllocation`, `VoidArPayment`. Needs: record AR payments. Docs: [AR payments](15-city-ledger/03-ar-payments.md#actions).
- [x] `feat(city-ledger): show AR payments` — Payments tab on the company page, Record payment and Allocate dialogs, allocations on the invoice page, receipt PDF. Needs: reverse allocations. Docs: [AR payments](15-city-ledger/03-ar-payments.md#pages).
- [x] `feat(city-ledger): check credit limits` — `CreditExposure`, `CreditCheck`, `company.credit-override`, the checks in `SettleToCityLedger`, `CreateReservation` and `UpdateReservationCompany`, `credit.overridden` log. Backend only. Needs: AR payments. Docs: [Credit control](15-city-ledger/04-credit-control-and-reports.md#credit-check).
- [x] `feat(city-ledger): add aging and statement services` — `AgingReport`, `StatementBuilder`. Needs: check credit limits. Docs: [Credit control and reports](15-city-ledger/04-credit-control-and-reports.md).
- [x] `feat(city-ledger): show aging, statements and credit warnings` — Aging page with CSV and PDF, Statement tab with PDF, credit override fields on settle, change company and booking, Over limit badges. Needs: aging and statement services. Docs: [Credit control and reports](15-city-ledger/04-credit-control-and-reports.md).

### 16 Registration

- [x] `feat(registration): add registration card fields` — a Registration card Settings page (General and Layout tabs, the old Details tab moves there), 18 field rows with Hidden, Optional or Required, display-only stay details and prices, `SeedRegistrationCardFields`, `SaveRegistrationCardFields`, the Layout table with a live preview. Settings only; the card itself comes with the signing flow. Docs: [Card fields](16-registration/01-card-fields.md).
- [x] `feat(registration): add terms documents and presets` — `terms_documents` and versioned `registration_card_terms`, the `room_types.terms_document_id` foreign key and a Terms document Select on the room type form, ten preset files (five presets in English and Bahasa Malaysia, each with 3 to 5 checklist items), `TermsPreset`, `TermsPlaceholder` (five placeholders), `TermsRenderer` (also parses checklist items from the terms markdown, at most 10), `SeedRegistrationTerms`, `CreateTermsDocument`, `PublishRegistrationTerms`, `ApplyTermsPreset`, `DeleteTermsDocument`, `registration-card.manage-terms` with a syncing migration, and the Terms tab with a language Select, version history and a newer-template banner. Docs: [Terms documents and presets](16-registration/02-terms-documents-and-presets.md).
- [x] `feat(registration): resolve terms by locale` — `RegistrationTermsResolver` and `ResolvedTerms`, with Pest tests; the locale selector comes with the card page. Docs: [Terms resolution and locales](16-registration/03-terms-resolution-and-locales.md).
- [x] `feat(registration): add the desk signing flow` — `registration_cards`, `RegistrationCardStatus`, `PrepareRegistrationCard`, `SignRegistrationCard` (required fields, terms checklist ticks and the server check, snapshot), `VoidRegistrationCard`, `RegistrationCardRequirement`, the staff card page with the signature pad and language Select, `lang/ms.json`, `registration-card.sign`, `.void` and `.send-link` with a syncing migration. Docs: [Signing flow](16-registration/04-signing-flow.md).
- [x] `feat(registration): hand the card to the guest` — read-only staff page with `SaveRegistrationCardDraft` (ID document, vehicle plate), the bare guest layout and guest screen, Agree to all, the inline signature pad with a full-screen mode, the thank-you screen that walks to the next guest of the unit. Replaces the signature dialog. Docs: [Signing flow](16-registration/04-signing-flow.md).
- [x] `feat(registration): render the signed card as a PDF` — `RegistrationCardDocumentBuilder` (signed snapshot or blank draft), `RegistrationCardPdf`, `StoreRegistrationCardPdf` (the `pdf` media, rendered on first download), `pdf.registration-card`, `registration.pdf` and `registration.blank` routes, Download PDF and Print blank form buttons, a Noto Sans SC subset (GB2312) and Noto Sans Symbols 2 in `resources/fonts`, the signature block kept off the footer. Docs: [Signing flow](16-registration/04-signing-flow.md).
- [x] `feat(registration): add remarks to the registration card` — `RegistrationCardField::Remarks` (19 fields, Optional by default), `registration_cards.remarks`, a back-fill migration, remarks in `SaveRegistrationCardDraft` and the snapshot, the staff page textarea, the guest screen Preferences textarea, the PDF and blank form. Docs: [Card fields](16-registration/01-card-fields.md), [Signing flow](16-registration/04-signing-flow.md).

### 23 Company portal

- [x] `refactor(reservations): rename the Agent source to Portal` — `ReservationSource::Agent` → `Portal`, generated TS, icon map. Docs: [Reservation shape](12-reservations/01-reservation-shape.md).

### Deferred items to pick up along the way

- [x] `feat(platform): store uploads on the chosen media disk` — with the first media collection (guest ID scans). Docs: [Platform settings](01-platform/02-platform-settings.md#media-storage).

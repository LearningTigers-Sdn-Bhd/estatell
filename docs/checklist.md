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

The critical path: the front desk actions on the reservation, then the night audit, then the core reports. Build in this order. Item 6 is a refactor that no later item waits for, so it can slip if the critical path is urgent.

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
9. [x] `feat(front-desk): extend and change stay dates` — `ChangeUnitDates` (a shorter stay runs `ProcessEarlyDeparture` and the early departure policy; nights that stay keep their prices, added nights are quoted alone), `ExtendStay` (a wrapper over it), `ExtendDepartureTime` (late check-out and its policy fee), `AddNightlyAddOnRows`. Backend only. Needs: cancel, no-show and reinstate. Docs: [Check-in and check-out](18-front-desk/01-check-in-and-check-out.md).
10. [x] **Add the unit actions to the reservation page**, in three commits, in this order. UI, thin routes and read-only previews (`UnitActionPreview`, `UnitActionMenu`). Needs: FormOverlay, the items before it. Docs: [Check-in and check-out](18-front-desk/01-check-in-and-check-out.md#forms-on-the-reservation-page).
    - [x] `feat(front-desk): add unit actions to the reservation page` — the unit ⋯ menu (only what `canTransitionTo()` and the permissions allow), the previews, and the cancel and no-show Dialogs with the policy fee, Do not charge and **Also remove add-ons** (unticked by default), the reinstate Dialog with its required reason and the change dates Dialog with a price difference.
    - [x] `feat(front-desk): check in and check out from the reservation page` — the check-in Dialog with Collect now (flagged taxes and the security deposit, two `RecordPayment` calls), the check-out Dialog with the deposit refund, and the late check-out Dialog. Needs: the unit actions menu.
    - [x] `feat(front-desk): check in all units of a group` — one Dialog with a row for each unit. A failed unit does not stop the others, and no payment is taken. Needs: check in and check out from the page.
11. [x] `feat(front-desk): assign and move rooms and beds` — `MoveUnit`, `AutoAssignRooms` (called by check-in), two partial unique indexes on `unit_nights`, the Move room Dialog in the unit ⋯ menu. Needs: unit actions on the page. Docs: [Room and bed assignment](18-front-desk/02-room-and-bed-assignment.md).
12. [x] `feat(front-desk): check out early in one step` — `EarlyCheckOut` runs `ProcessEarlyDeparture` (the early departure fee, the unused nights deleted) and `CheckOutAndRefund` in one transaction, so a refused check-out also undoes the fee. `UnitActionPreview::earlyCheckOut()`, an early mode in the check-out Dialog, `front-desk.check-out` and `reservations.manage` on the route. Needs: unit actions on the page. Docs: [Check-in and check-out](18-front-desk/01-check-in-and-check-out.md#early-check-out).
13. [x] `feat(onboarding): add go live and the live middleware` — **moved forward from 17**: the night audit needs an open business date. `GoLive` calls `OpenBusinessDate`, starts document numbering and sets `Live`; the readiness check starts empty and fills as the wizard steps land. `EnsurePropertyIsLive` on operational routes. Docs: [Go live](17-onboarding/03-go-live.md#build-order).
14. [x] `feat(night-audit): post room charges and scheduled add-ons` — `NightChargeQuote` (`app/Services/Folio/`: the room, inclusion, discount and tax lines of one night; `ScheduledFolioRows` uses it too), `PostRoomCharges` (one unit, one business date), `PostScheduledAddOns`. `PostFolioCharge` takes a nullable actor so an unattended audit can post. `InclusionAllocator` and the undated add-ons at check-in are already built. Needs: go live, post charges, folio routing. Docs: [Posting charges](13-folio/02-posting-charges.md#room-charge-pipeline-per-unit-night).
15. [x] `feat(folio): show the unit on posted folio rows` — every charge line sets a source that names its unit: night, inclusion and add-on lines as today (the inclusion source was missing), manual charges and policy fees name the reservation unit, and `ReverseLine` and `TransferLines` copy the source. `FolioStatement` reads the unit through the source, a child takes its parent's, and the Unit column shows for posted rows on every folio type. Needs: post room charges and scheduled add-ons. Docs: [Folio page](13-folio/05-folio-page.md), [Posting charges](13-folio/02-posting-charges.md#line-sources).
16. [x] `feat(night-audit): run the night audit` — `RunNightAudit`, `ClaimBusinessDate`, the step runners for missed arrivals, overdue departures, room charges (a `Bus::batch` on the sync connection), scheduled add-ons, room statuses and `CloseAndOpenNext`, `night_audits`, `night_audit_steps` and `night_audit_exceptions`, the `night-audit.*` permissions with a syncing migration, and a nullable actor on the front desk Actions the audit calls. Backend only. `SnapshotStatistics` joins with item 19. Needs: no-show and check-out Actions, room assignment, post room charges. Docs: [Audit run](20-night-audit/03-audit-run.md).
17. [x] **Add the Run Night Audit sheet**, in two commits, in this order. Docs: [Readiness panel](20-night-audit/02-readiness-panel.md). Needs: run the night audit.
    - [x] `feat(night-audit): add the audit readiness service` — `AuditReadiness` and `AuditCheck` (no room tonight is the one blocker; not checked in, not checked out, folio open after check-out and carried exceptions are warnings), the `blocked_by_readiness` gate in `RunNightAudit`, `RunNightAuditJob` in the new `app/Jobs` folder (with `PostRoomChargesJob` moved there), and the sheet data and routes (`night-audit.show`, `run`, `units.assign`). Backend only.
    - [x] `feat(night-audit): add the Run Night Audit sheet` — the sidebar button (a nav item with an `action`) and the Sheet on `FormOverlay`: ready, blocked, running, failed and completed states, **Assign**, **Force close** with a reason. UI only. Needs: the audit readiness service.
18. [x] `feat(night-audit): schedule the night audit per property` — Docs: [Scheduling](20-night-audit/04-scheduling.md).
19. [x] **Snapshot statistics and notify the audit result**, in two commits, in this order. Docs: [Statistics](20-night-audit/05-statistics.md). Needs: schedule the night audit. The report packet PDF came with the core reports item.
    - [x] `feat(night-audit): snapshot daily statistics` — `daily_statistics` and `daily_revenue`, models, `DailyRevenueQuery` (`app/Services/Reports/`, the one live revenue query that the snapshot and the core reports share), `StatisticsCalculator`, `SnapshotStatistics` and `SnapshotStatisticsStep` as step 7 (`AuditStep::SnapshotStatistics`, idempotent upsert). Pest tests, including a run-twice test and a test that the stored rows equal the live query.
    - [x] `feat(night-audit): notify the audit result` — `NotifyNightAuditResult`, run by `NotifyNightAuditResultJob` that `RunNightAudit` queues when an audit completes or fails (not from `CloseAndOpenNext`, so a message error never changes a closed date), `MealCounts` and `AuditResultSummary`, with `NightAuditCompleted`, `NightAuditExceptions` and `NightAuditFailed` content (occupancy, ADR, RevPAR, revenue by code, payments by method, meal counts, exceptions). Needs: the snapshot.

20. [x] **Add the core reports**, in this order. Docs: [Core reports](22-reports/01-core-reports.md), [Printing settings](02-property/05-printing-settings.md), [Statistics](20-night-audit/05-statistics.md). Needs: snapshot statistics.
    - [x] `feat(property): add printing settings and the logo` — backend and PDFs. `print_settings` (seeded by `SeedPropertyDefaults`, back-filled for existing properties) and `print_setting_taxes`, `PdfFont`, `PdfFontSize`, `UpdatePrintSettings`, `UpdatePrintHeader`, `HasMedia` and the `logo` collection on Property with `UploadPropertyLogo`, `RemovePropertyLogo` and the `settings.logo.*` routes, `PrintProfile` (replaces `Services/Folio/Letterhead`), `PrintedPdf` (the font check, paper and orientation; every PDF controller uses it), the letterhead partial and the footer in `pdf/layout.blade.php`. The receipt, voucher, AR invoice, AR receipt, AR statement and aging get them and drop their own property block; the registration card and the temporary password slips do not. The `settings.printing.*` update routes and the sample PDF. Docs: [Printing settings](02-property/05-printing-settings.md), [Properties](02-property/01-properties.md#profile).
    - [x] `feat(property): add the profile tab and printing pages` — UI. The Details page Profile tab (first tab, the sidebar opens it) with `UpdatePropertyProfile` and the logo upload; the Printing settings item with the General and Header tabs, the browser preview, Open sample PDF and the `use-auto-save` hook. Needs: printing settings and the logo.
    - [x] `feat(property): add PDF font styles` — `PdfFont` becomes Office, Modern, Fancy and Handwritten (a heading and a body font each, falling back to Noto Sans SC and TC), the font files in `resources/fonts/`, a migration from Standard and Chinese to Office, the style cards on the General tab and the fonts in the preview. English and Chinese only. Docs: [Printing settings](02-property/05-printing-settings.md#fonts).
    - [x] `feat(reports): add the report registry and the arrivals report` — `ReportKey`, `ReportColumn`, `PageOrientation`, the `Report` interface, `ReportFilters`, the `presets` prop on `DateRangePicker` (counted from the business date), `reports.view` with a syncing migration, and the Arrivals page as the first report. Needs: the profile tab and printing pages.
    - [x] `feat(reports): add report layouts` — `report_layouts`, `report_layout_columns`, `ReportLayoutResolver`, `UpdateReportLayout`, `ResetReportLayout`, the Layout Sheet (`settings.manage`) with Preview PDF. Needs: the report registry.
    - [x] `feat(reports): export selected rows as PDF, CSV or Excel` — the checkbox column, `reports.pdf` and `reports.spreadsheet` (POST row keys, the server re-runs the query), layout order, visibility and orientation in every format. Needs: report layouts.
    - [x] `feat(reports): add departures and in-house` — `ReportCategory` grows to Front desk, Revenue, Finance and Kitchen and groups the Reports sidebar, `presets` on `DatePicker` (Today, Yesterday), a shared `ReportScreen` page component that Arrivals moves onto, then Departures and In-house. Needs: export selected rows. Docs: [Core reports](22-reports/01-core-reports.md#other-report-columns).
    - [x] `feat(reports): add occupancy and revenue` — Occupancy (`daily_statistics`, `StatisticsCalculator` for the open date, marked Live), Revenue (live `DailyRevenueQuery`, the `group` day or month toggle), and the optional `totals()` row in the table, PDF and spreadsheet. Needs: departures and in-house.
    - [x] `feat(reports): add payments, folio ledger and tax` — Payments with totals per method, Folio ledger with the `code` filter, the Revenue drill-down to it, and Tax (tax lines do not store their base, so no Base column). Needs: occupancy and revenue.
    - [x] `feat(reports): add kitchen meal counts` — Meal counts from `MealCounts::between()` per date and meal period, with adults and children, Checked-out units counted, and its own quick dates (Today, Tomorrow, Next 7 days, This week, Next week). Needs: payments, folio ledger and tax.
    - [x] `feat(reports): add the night audit packet PDF` — one PDF per closed date from the snapshot tables, opened from Night audit history. Needs: kitchen meal counts.
    - [x] `feat(reports): add cashier activity and refunds` — from wastays: a Received by grouping with a cash-up total per staff member and a Refunds only filter on Payments. Needs: the night audit packet PDF.
    - [x] `feat(reports): add outstanding balance and deposit liability` — from wastays: two one-date reports. Needs: cashier activity and refunds.
    - [x] `feat(reports): add non-national guests and the police report` — from wastays: foreign guests with their tourism tax, and the police list for every guest with a Foreign only filter, with full country names, masked ID numbers and a log entry for exports that hold full numbers. Needs: outstanding balance and deposit liability.

21. [x] **Make deposits and the city ledger safe**, in three commits, in this order. From a review of single and group flows and of wastays bugs (night audit trapped by company billing, unpaid bookings shown as paid, money left on cancelled bookings, company payments missing from the folio). Docs: [Payments and refunds](14-payments/01-payments-and-refunds.md), [AR invoices](15-city-ledger/02-ar-invoices.md), [Companies](15-city-ledger/01-companies.md#rules), [Routing](13-folio/03-routing.md).
    - [x] `fix(folio): block wrong deposit and city ledger actions` — `units_in_house` in `CloseFolio` (Master only) and `SettleToCityLedger` (before any write), with `InHouseUnits`; `advance_on_master` and `deposit_on_unit_only` in `RecordPayment`, with `FolioRouter::roomChargesGoToMaster` shared with `CheckInCollection`; `master_has_payments` in `UpdateReservationGroup` (with `MasterPaymentsHeld`, a refusal on `GroupUpdateResult`, and the controller showing it); the company change moves the Master payer with its charges and refuses only `master_has_payments` or `master_has_ar_invoice` (`FollowCompanyOnMasterFolio` returns a code); purposes by stage (`PaymentStage`, `PaymentPurpose::forStage`, `PaymentPurposeChoices`, `FolioData.paymentPurposes` and `defaultPaymentPurpose`, purposes leave `PaymentFormOptionsData`); a company payer on the third-party folio Dialog and company names on folios; `folio_settled_to_city_ledger` from `FolioPostability` in `PostCharge`, `PostRoomCharges` and `PostScheduledAddOns`, with its readiness text. Pest tests for each refusal, the path that must keep working, and "writes nothing".
    - [x] `feat(night-audit): warn about unbilled and unrefunded folios` — `AuditCheck::MasterNotBilled` and `MoneyHeldOnClosedBooking` (warnings, one row per reservation, an Open folio link carrying the folio), the new sheet summary sentence; `CheckOutUnit` closes the unit's Open Unit folios through `CloseFolio`; a CheckedOut unit's folio closes itself at 0 after a posting, payment, refund, reversal or transfer; `FolioReopenRule` (front desk reopens a Unit folio closed today) with `FolioData.closedNotice` and the Reopen folio notice; the `folio_not_open` message tells staff to reopen. Needs: the fix above. Docs: [Readiness panel](20-night-audit/02-readiness-panel.md), [Check-in and check-out](18-front-desk/01-check-in-and-check-out.md), [Close and reopen](13-folio/01-folios-and-lines.md#close-and-reopen).
    - [x] `fix(city-ledger): count unbilled master folios in credit exposure` — every Open company folio counts in `CreditExposure` whatever the reservation status (the `inHouse` key stays, the company page says "Open folios"); `SettleToCityLedger` is never refused by the credit limit and `FolioData.settleCreditWarning` warns in its confirmation; tests in `CreditControlTest`, `SettleToCityLedgerTest`, `CityLedgerReportPagesTest`. Needs: the warnings above. Docs: [Credit control](15-city-ledger/04-credit-control-and-reports.md), [AR invoices](15-city-ledger/02-ar-invoices.md).
    - [x] `feat(reservations): show paid and billed to company apart` — `ReservationDirectory` splits Paid from DIRECT_BILL (`billedToCompany`, a muted sub-line in the list and card); `FolioPaymentReferences` with the JSON reference check route and the warning in the Record AR payment Dialog (folio payments and other AR payments); the `eachPaysHint` in the booking rail, Edit group and Change company (which drops its wrong "billed through the master folio" line). Docs: [Money strip](12-reservations/06-reservation-page.md#money-strip), [AR payments](15-city-ledger/03-ar-payments.md#rules).

22. [x] **Print customer documents**, in four commits, in this order. From a review of wastays booking summary and invoice fixes (missing bill-to, "Fixed line: -", "Total payments 0.00", a summary that missed extra charges, company invoices that did not name the staying guests). Docs: [Printing settings](02-property/05-printing-settings.md#documents-tab), [Booking summary](12-reservations/08-booking-summary.md), [Folio invoice](13-folio/06-folio-invoice.md), [AR invoices](15-city-ledger/02-ar-invoices.md#pdf).
    - [x] `feat(printing): add document switches` — the six switches and `bank_details` on `print_settings` (defaults as in the doc, `bank_details` CHECK like `footer_text`), `UpdatePrintDocuments`, the Documents tab with its preview and `settings.printing.sample?document=booking-summary`. The shared line grouper `DocumentLines` with `DocumentRow` input (unit grouping, collapsed room nights, tax summary, reversal pairs left out), `LineSourceUnits` moved out of `FolioStatement`, the `pdf/partials/document-lines.blade.php` partial and `pdf/document-sample.blade.php`, with Pest tests. `AmountInWords` on `intl` (`en_GB`, `%spellout-numbering-verbose`) with `Currency::wordsName()` and `minorUnitWords()`.
    - [x] `feat(reservations): print a booking summary` — `BookingSummary` and `BookingSummaryDocument` (`app/Services/Reservations/`, rows built from posted lines and `ScheduledFolioRows`, not `FolioStatement`), `BookingSummaryController`, `pdf/booking-summary.blade.php`, `reservations.booking-summary` with `?prices=0`, **Print booking summary** and **Print without prices** in **⋯ More**. Reference and "As at", no number, nothing stored. The booking summary sets `room` on its rows (the room number, or the room type code when unassigned). Tests: the totals match the folios' Projected totals for a single room, a MasterPays group, a third-party folio and a transferred charge; paid, billed and balance match `ReservationDirectory`; posted and Scheduled nights merge; its PDF shows the Room column for two units and hides it for one; without prices prints no amounts; 403 and 404. Needs: document switches.
    - [x] `fix(reservations): show no-shows on the booking summary` — the NO-SHOW stamp, every unit in Rooms with its status, "No charges" without Total or Balance due when nothing is charged, Balance due as a Credit when only payments are held, and the `DocumentRow` docblock says room type name. Docs: [Booking summary](12-reservations/08-booking-summary.md#rules).
    - [x] `feat(folio): print a folio invoice` — `FolioInvoice` and `FolioInvoiceDocument` (`app/Services/Folio/`), `pdf/folio-invoice.blade.php`, `reservations.folios.invoice` (Closed folios only), **Print invoice** in the folio header and the closed notice, the `folio.print-voucher` label "Print folio vouchers and invoices". `FolioDocumentRows` taken out of `BookingSummary` (no change to the summary), `DocumentRow::$taxNumber` and the tax numbers in the tax summary, the Rooms block with the switch off, and no rate plan in Rooms on the booking summary without prices. Needs: the booking summary.
    - [x] `feat(city-ledger): apply document switches to the AR invoice` — the folio lines under room and staying guest (through `FolioDocumentRows`; the Rooms block with the switch off, so the staying guests always print), collapsed nights, tax summary with tax numbers, amount in words of the Amount due and payment details; "Paid at the hotel" (`FolioPaymentRows`) then Amount due = the invoice amount; the info strip without empty cells (Reservation reference instead of the folio number); no lines for an invoice without a folio; the credit note unchanged; `CompanyBillTo` prints no country-only address. Needs: the folio invoice.

23. [x] `fix(city-ledger): explain how to credit a paid invoice` — Issue credit note shows on every invoice that is not Void; `ArInvoiceData.creditBlockedBy` (active allocations); the Dialog's three cases (balance above 0, balance 0 with a Reverse button per payment, amount over the balance); `credit_exceeds_balance` names the balance and the payments (read by the controller after the refusal). Reverse closes the credit Dialog and opens the Reverse allocation Dialog, which returns to the credit Dialog when it closes. Without the reverse permission, the payments show without Reverse and with "Ask a user who can reverse payments." `IssueCreditNote` does not change. Tests for each case, and reverse then credit at the new balance. Independent of item 22, so it can go first. Docs: [AR invoices](15-city-ledger/02-ar-invoices.md#pages).
    - [x] `fix(folio): link a settled folio to its invoice and credit note` — **Open invoice** and **Issue credit note** in the closed notice of a folio with an AR invoice (permissions as in the doc, outline buttons), `?credit=1` opening the credit note Dialog on the AR invoice page. Tests: the notice actions follow the permissions; `?credit=1` is ignored without `canCreditNote`. Needs: item 23. Docs: [Folios and lines](13-folio/01-folios-and-lines.md#close-and-reopen), [AR invoices](15-city-ledger/02-ar-invoices.md#pages).
24. [ ] `feat(reservations): add a guest after check-out` — `AttachGuest` accepts CheckedOut units (Cancelled and NoShow still `unit_not_open`), the Attach guest Dialog lists them with "Checked out" and the note, and the tax warning toast when a new primary on a CheckedOut unit differs from the booker in being foreign while a foreign-only tax is enabled (`AttachGuestResult` carries it). No folio, price or pax change. Tests: a CheckedOut unit takes a guest and posts nothing; Cancelled and NoShow are refused; the warning shows only for a new primary with a different foreign status. Independent of items 22 and 23. Docs: [Reservation page](12-reservations/06-reservation-page.md#adding-a-guest-after-check-out).
25. [ ] `feat(folio): move charges between folios on one page` — the two-column Move charges page (`reservations.folio.move`, `.preview`, `.store`), `MoveCharges` and `MoveChargesResult`, `MoveChargesPreview`, `TransferLines` accepting several source folios, `FolioRouter` with proposed rules for the preview, the scheduled choices (unit + Room, or unit + Room and unit + all charges) with a same-selector Manual rule replaced, one `folio.charges_moved` log entry, the 200-charge limit, **Move…** in the row menu and **Move charges** in the folio header; the single-row Transfer Dialog and `reservations.folios.transfers.store` are removed. Tests as in the doc. Docs: [Move charges](13-folio/07-move-charges.md), [Posting charges](13-folio/02-posting-charges.md#transferlines), [Routing](13-folio/03-routing.md#staff-screens), [Form containers](design-system/05-form-containers.md#sizes).
26. [ ] `fix(front-desk): check out rooms that charge to another folio` — `IncomingScheduledCharges`, `folio_receives_scheduled_charges` in `CloseFolio` (every close, also Settle to City ledger) and in `CheckOutUnit` before the balance check, the check-out preview splitting the unit folio balance by room with the "pays for another room" note and **Move charges**, and messages that name rooms and dates. Tests: a unit whose folio receives another live unit's scheduled nights cannot check out, close or settle; it can once the rule changes or the last night posts; the balance split; a folio with only its own charges shows nothing new. Needs: item 25. Docs: [Check-in and check-out](18-front-desk/01-check-in-and-check-out.md#rooms-that-charge-to-another-rooms-folio), [Close and reopen](13-folio/01-folios-and-lines.md#close-and-reopen), [AR invoices](15-city-ledger/02-ar-invoices.md#rules).
27. [ ] `feat(reservations): change the price of booked nights` — `ChangeNightPrice` and `NightPriceResult`, `NightPriceChangePreview`, the shared posting step taken out of `PostRoomCharges` with the `night:{id}:fix:{n}` keys, `FolioStatement` reading `:fix:` lines as the night's posted lines, `reservations.change-price` with a syncing migration (Owner, Manager), **Change price** on the night table and **Correct price…** on a posted room line, the dialog. Tests as in the doc. Independent of items 22–26. Docs: [Change a night's price](12-reservations/09-change-night-price.md), [Posting charges](13-folio/02-posting-charges.md#room-charge-pipeline-per-unit-night).
28. [ ] **Sell day use**, in four commits, in this order. A hotel quotation includes day use. Front desk only, per-room room types, a price per number of hours. Docs: [Day use](12-reservations/10-day-use.md), [Rate plans](08-rates/01-rate-plans.md#day-use-plans), [Day use prices](08-rates/02-daily-rates-and-restrictions.md#day-use-prices), [Room revenue](06-catalogues/06-room-revenue.md#day-use-tab).
    - [ ] `feat(rates): add day use rate plans` — `PricingBasis::DayUse`, the plan columns and CHECKs, `rate_hour_prices`, Hours sold and Sold between on Details, hour rows and Auto on the Pricing tab, the calendar cell, DAY_USE seeded and back-filled, the Room revenue Day use tab with `UpdateDayUseTaxes`, the channel refusal.
    - [ ] `feat(reservations): book day use` — `StayType`, `unit_day_uses` with the `btree_gist` exclusion constraint, `DayUsePricing` and `quoteDayUse`, `DayUseAvailability`, the day use path in `CreateReservation` and `AddUnit`, the booking form switch, the "Day use · 4h" labels, the Scheduled folio row. Needs: day use rate plans.
    - [ ] `feat(front-desk): check day use in and out` — check-in posting the block and its inclusions, check-out, cancel and no-show as one night, reinstate, `MoveUnit`, `AutoAssignRooms` skipping overlapping rooms, the audit detail. Needs: book day use.
    - [ ] `feat(reports): show day use apart` — `day_use_sold` and `day_use_revenue` in `daily_statistics` and `SnapshotStatistics`, the Occupancy columns, the stay column in Arrivals, Departures and In-house. Needs: check day use in and out.
29. [ ] **Charge items: a full page, child prices and auto-add**, in three commits, in this order. Docs: [Charge items](06-catalogues/02-charge-items.md#pages), [Add-ons](12-reservations/04-add-ons.md#added-automatically).
    - [ ] `refactor(catalogues): edit charge items on their own page` — the New charge item Dialog (Name, Code, Category; creates with Enter when charging, Per item, no taxes) that opens the edit page, and the one-column edit page (Details, Pricing, Taxes) on the rate plan editor shell with `useUnsavedChanges`. The add and edit Sheet goes away. No behaviour change.
    - [ ] `feat(catalogues): price children on per-guest charge items` — `charge_item_child_prices` (back-filled at Percent 100), `needs_child_price_review`, the Children section reusing the rate plan control, `SaveAgeGroups` keeping items in step, `unit_add_ons.property_age_group_id`, and `ChargeQuantityResolver` splitting rows by price. Tests: the split for adults, a child and an infant; nightly rows; Free groups write no row; the default keeps today's totals. Needs: the edit page.
    - [ ] `feat(reservations): add charge items to every new booking` — `charge_items.auto_add` with its CHECK, the New bookings section and the Auto badge, `AutoAddOns`, the booking form prefill with the tag, × and `declined_auto_add_ons` log, the server-side add in Add unit and the company portal, none for channels, nightly items skipped for day use. Needs: child prices.
30. [ ] `fix(reservations): label the room total and show the full total` — the money strip's Total becomes **Room total** with the tooltip, a negative Balance gets the **Deposit held** badge, and the summary card shows Total, Paid, Billed to company and Still to pay from `BookingSummary`. Tests: the card matches the booking summary; the badge shows only below 0. Needs: item 22 (booking summary). Docs: [Money strip](12-reservations/06-reservation-page.md#money-strip), [Summary card](12-reservations/06-reservation-page.md#summary-card).
31. [ ] `feat(front-desk): cancel a whole reservation` — `CancelUnits` (all or nothing over `CancelUnit`) and `CancelUnitsResult`, `UnitActionPreview::cancelAll()`, `reservations.cancel` and its preview route, **⋯ More › Cancel reservation** with the Dialog (rows by unit, one Do not charge and reason, Also remove add-ons, the money line), the company portal request banner and its resolution. Tests: a group cancels in one go; one refusal cancels nothing; a CheckedIn unit is not offered; the money line; the request is resolved. Docs: [Cancel reservation](18-front-desk/01-check-in-and-check-out.md#cancel-reservation).
32. [ ] `fix(night-audit): fix the price of a stuck package night` — `FixPackageNightPrice` with its preview and Dialog replacing Retry on `exceeds_night_amount` package rows, **Dismiss** with a reason on any carried exception, and `override_total` on a package spread with `PackagePricing::fitInclusions()` (refused below the inclusions). Tests: the Sandbay Dive Package case (586.67 × 3 with diving on night 1) posts after Fix price and keeps 1,760.00; charged nights untouched; refused when the rest cannot cover the inclusions; Dismiss resolves without posting; a package override spreads by inclusions. A re-seed of `SandbaySeeder` already books through `PackagePricing`, so only old dev data needs the fix. Docs: [Readiness panel](20-night-audit/02-readiness-panel.md#fix-price-of-a-package-night), [Package nights](08-rates/03-pricing-strategies.md#package-nights-and-inclusions).
33. [ ] `feat(front-desk): check out a group on one page` — the group check-out page (`reservations.check-out`, `.preview`, `.store`), `CheckOutGroup` (all or nothing: moves, `CheckOutAndRefund` per row, money only for rooms already out or cancelled, optional `SettleToCityLedger`), Collect, Add to Master and Refund per room, One payment for all, Print receipts, **⋯ More › Check out all**, and the night audit and dashboard group rows linking to it. Tests as in the doc. Needs: items 25 and 26. Docs: [Group check-out](18-front-desk/05-group-check-out.md).

## Next

Needed for go-live, but nothing in Now waits for them.

- [ ] `feat(housekeeping): add the room status board` — Docs: [Room status board](19-housekeeping/01-room-status-board.md).
- [x] `feat(catalogues): add the collect at check-in switch to taxes` — `taxes.collect_at_check_in` (default false, on for the Tourism tax preset) and the switch on the tax form. Item 10 needs it for Collect now. Docs: [Taxes](06-catalogues/03-taxes.md).
- [ ] `feat(front-desk): add the dashboard` — replaces the starter kit placeholder: the night audit alert, quick actions (New booking, Walk-in, Find reservation, Run night audit), four link tiles (Arrivals left, Departures left, Tonight with 7 nights and a room type popover, the month's Posted and Booked) and the To do list (overdue, arrivals, departures, folio warnings) with Check in, Check out and Open folio through `AuditDialogHost`. `DashboardController`, `DashboardAlert`, `DashboardTiles`, `DashboardTodo`, deferred props with skeletons, the Dashboard archetype. Docs: [Dashboard](18-front-desk/04-dashboard.md).
- [ ] `feat(front-desk): add the stay view` — Docs: [Stay View](18-front-desk/03-stay-view.md).
- [ ] `feat(reservations): preview the price in the Add unit Sheet` — reuse the booking form quote route. Docs: [Reservations list and reservation page](12-reservations/06-reservation-page.md#header).
- [ ] `feat(onboarding): add the onboarding wizard steps` — Docs: [Wizard steps](17-onboarding/02-wizard-steps.md).
- [ ] `feat(onboarding): add reminders and the admin onboarding board` — Docs: [Engagement and admin board](17-onboarding/04-engagement-and-admin-board.md).
- [x] `feat(catalogues): give a room revenue policy its own taxes` — `room_revenue_policies.own_taxes` (default false); `UpdateRoomRevenueTaxes` skips a policy code that has its own taxes; a Taxes section (third column of row 1) with a **Follow the Taxes tab** switch and a multiselect on each policy tab; the Taxes tab also becomes a multiselect with the rule as each option's description; the preview takes the unsaved choice; a test that a Taxes tab save keeps an override. Needs: room revenue policy tabs. Docs: [Room revenue](06-catalogues/06-room-revenue.md#own-taxes).
- [ ] `feat(rates): override the early departure policy per rate plan` — `rate_plans.early_departure_policy` becomes an optional override that follows the property by default. Needs: room revenue policies. Docs: [Rate plans](08-rates/01-rate-plans.md), [Room revenue](06-catalogues/06-room-revenue.md#policies).
- [x] **Seed the booking scenarios**, in two commits, in this order. A second demo property with every single, group and dorm bed case, rolled forward with the night audit. No app code changes. Docs: [Booking scenarios seeder](12-reservations/07-booking-scenarios-seeder.md).
    - [x] `feat(seeders): seed the booking scenarios property` — `BookingScenariosSeeder`: Kinabalu Hostel & Suites, the first business date 7 days back, Mark no-show, the room types, dorm beds, plans, rates with the D15 restriction, the company and guests, every booking on D0, the scenario table at the end. Test: the property, plans and reservation count, and a second run adds nothing.
    - [x] `feat(seeders): roll the booking scenarios forward` — the day loop from D0 to D6 (check-ins, moves, extensions, cancellations, payments, check-outs, then `RunNightAudit`) and the D7 events. Test: each scenario code has its expected status, 7 Closed dates, S1 has two posted nights. Needs: seed the booking scenarios property.
- [ ] `feat(ui): add the command palette` — ⌘K search across contexts; admin search by code, name, city. Docs: [Search patterns](design-system/07-search-patterns.md).

## Later

### 21 Channels

Needs the Channex staging checks in [Staging verification](21-channels/06-staging-verification.md) before `NativeDorm` work.

- [ ] `feat(channels): connect Channex behind the channel manager flag` — `EnsureFeaturesAreActive`, upsell page. Docs: [Connections and feature gate](21-channels/01-connections-and-feature-gate.md).
- [ ] `feat(channels): map room types and rate plans` — Docs: [Mappings and capability](21-channels/02-mappings-and-capability.md).
- [ ] `feat(channels): add dorm strategies` — Docs: [Dorm strategies](21-channels/03-dorm-strategies.md).
- [ ] `feat(channels): sync availability, rates and restrictions` — Docs: [ARI sync](21-channels/04-ari-sync.md).
- [ ] `feat(channels): ingest channel bookings` — Docs: [Booking ingestion](21-channels/05-booking-ingestion.md).
- [ ] `feat(reports): add booking performance` — from wastays: bookings and revenue by source, room type and period. Needs: ingest channel bookings. Docs: [Core reports](22-reports/01-core-reports.md).

### 23 Company portal

- [ ] `feat(portal): add company users and company links` — `is_company_user`, users CHECK, `company_users`, Portal tab with invite, temp password and bulk add, existing-account invitations. Docs: [Company portal](23-company-portal/01-company-portal.md#adding-company-users).
- [ ] `feat(portal): add the portal property list and invitations` — `/portal`, accept and decline, `portal` middleware, home redirect. Docs: [Company portal](23-company-portal/01-company-portal.md#pages).
- [ ] `feat(portal): let company users book and manage stays` — `portal_access`, booking checks, cancel and request. Docs: [Company portal](23-company-portal/01-company-portal.md#rules).
- [ ] `feat(portal): show company statements` — read-only invoices, payments and statement PDF. Docs: [Company portal](23-company-portal/01-company-portal.md#pages).

### 24 Boats

For island and jetty properties, behind the `Boats` feature. Build in this order.

- [ ] `feat(boats): add boat departures and fares` — the `Boats` feature and `PlatformFeature::Boats`, `boat_departures` with its CHECKs, `BoatDirection`, `TransactionCategory::Transport`, the **Boats** settings item (Property group), the Departures and Fares tabs with the Available switch, the departure Sheet, the fare Sheet, `CreateBoatDeparture`, `UpdateBoatDeparture` (moves upcoming unit times), `DeleteBoatDeparture`, `CreateBoatFare` and `UpdateBoatFare` over the charge item Actions, and the disabled auto-add switch on a fare in use. Needs: item 29 (child prices and auto-add). Docs: [Boat settings](24-boats/01-boat-settings.md).
- [ ] `feat(boats): set boat trips on units` — `unit_boat_trips`, `BoatMode`, `BoatTripData`, `SetBoatTrip` and `ClearBoatTrip` (unit times, refusals), `BoatSeats`, Boat in and Boat out on the booking form, `NewUnitData::$boatTrips` through `AddUnit`, Add unit copying the first unit's trips, the Boat row and Change Dialog on the unit panel, **⋯ More › Set boats**. Needs: boat departures and fares. Docs: [Boat trips](24-boats/02-boat-trips.md).
- [ ] `feat(boats): add the boat fare to trips` — `boat_trip_add_ons`, the undated fare add-on through `AddUnitAddOn`, the package skip, removing unposted fare rows on change or clear, the posted-fare note. Needs: set boat trips. Docs: [Boat trips](24-boats/02-boat-trips.md#fare).
- [ ] `feat(boats): add the boat manifest` — `ReportKey::BoatManifest`, the `BoatManifest` service, the Direction filter, subtotals per departure, the PDF with a page per departure. Needs: set boat trips. Docs: [Boat manifest](24-boats/03-boat-manifest.md).
- [ ] `feat(meals): count meals by arrival and departure time` — `MealCounts` passes the unit times when the feature is on, and the resolver trims the first and last day. Needs: set boat trips. Docs: [Meal entitlements](07-meals/02-meal-entitlements.md#arrival-and-departure-times).
- [ ] `feat(front-desk): show boat times on arrivals and departures` — the Boat column on Arrivals and Departures, and the **No boat** row on the dashboard. Needs: set boat trips, the dashboard. Docs: [Boat trips](24-boats/02-boat-trips.md#front-desk).
- [ ] `feat(housekeeping): order rooms by expected times` — departures by `departure_time`, arrivals by `arrival_time`. Works without boats. Needs: the room status board. Docs: [Room status board](19-housekeeping/01-room-status-board.md).

### Front desk corrections

- [ ] `feat(reservations): void a reservation entered by mistake` — only with nothing posted left and no money left; `voided_at` and a reason on the reservation, units Cancelled with no fee, left out of reports and hidden from the default list. Docs: [Cancel, void and undo](18-front-desk/01-check-in-and-check-out.md#cancel-void-and-undo).
- [ ] `feat(front-desk): undo a check-in or check-out made today` — Undo check-in (same business date, no night posted, room released) and Undo check-out (same business date, room free tonight, unit folios reopened, payments kept; after an early check-out the nights and fee stay), each with a reason and its own permission. Docs: [Cancel, void and undo](18-front-desk/01-check-in-and-check-out.md#cancel-void-and-undo).

### E-invoicing

Planned after item 22. Plan first, with the official MyInvois specification; nothing below is decided.

- [ ] `docs(einvoice): plan MyInvois e-invoicing` — what is submitted (folio invoice, AR invoice, credit note, consolidated invoice for walk-in guests), a snapshot of each issued document at close or settle, the buyer fields on guests and companies (TIN, ID, address), a credit note path for a folio invoice, the "Reissue invoice" question for a paid AR invoice, a one-step credit for a paid invoice, and a refund of company credit (there is no AR refund). Needs: print customer documents.

### Registration and platform

- [ ] `feat(reports): add the activity log page` — the Audit item in the Reports sidebar, reading the existing activity log, scoped by property. Docs: [App shell](design-system/09-app-shell.md).
- [ ] `feat(registration): sign the card remotely` — `SendRegistrationCardLink`, signed `/g` routes on the step 1b guest screen, ID upload, a QR code of the link on the staff page (the QR package is chosen and approved at this step). Docs: [Signing flow](16-registration/04-signing-flow.md).
- [ ] Parked: `feat(registration): pair a signing tablet` — pairing code, Send to tablet, polling idle screen, per-property switch. Docs: [Signing tablet (parked)](16-registration/04-signing-flow.md#signing-tablet-parked).
- [ ] `feat(platform): view a property as its staff` — view-as page, `StartImpersonation`, `StopImpersonation`, banner, `EnsurePropertyAccess` steps 2 and 3. Needs: staff, property settings (`allow_support_access`). Docs: [Impersonation](01-platform/04-impersonation.md).
- [ ] `feat(platform): block risky actions during impersonation` — `block.impersonation` middleware.

### WIP: not planned yet

Topics on hold. Each one needs a planning session and its docs before it gets commit items. Do not build from these lines.

- [ ] **Booking engine** — estatell stays the PMS core and sends property content to a separate booking engine app through an API (Sanctum): property and room type photos, amenities, nearby attractions, public rate plans and how policies are shown. Guests pay at the property.
- [ ] **Concierge page** — like the booking engine, but for the guest's stay experience. After the booking engine.
- [ ] **AI concierge** — can be turned on only when the concierge page is on. Admins hold the API key for now. After the concierge page.
- [ ] **AroundThat programme** — recommends places from the AroundThat API. Later.

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
| `5814211` | refactor(folio): move overlays onto FormOverlay                          |
| `3e33cd8` | feat(front-desk): cancel, no-show and reinstate units                    |
| `e82a392` | fix(front-desk): release rooms on cancel and no-show                     |
| `065e49f` | feat(front-desk): add unit actions to the reservation page               |
| `753e731` | feat(front-desk): check in and check out from the reservation page       |
| `fc33bf4` | feat(night-audit): run the night audit                                   |
| `03ff0e7` | feat(night-audit): snapshot daily statistics                             |
| `656d1ff` | feat(night-audit): notify the audit result                               |
| `bb4377e` | docs(reports): plan printing settings and core reports                   |
| `0e6cbc2` | docs(property): plan the profile tab and printing pages                  |
| `4d68144` | feat(property): add printing settings and the logo                       |
| `fb54dd7` | feat(property): add the profile tab and printing pages                   |
| `0e870da` | feat(property): add PDF font styles                                      |
| `9e308f0` | docs(reports): settle arrivals columns and report paging                 |
| `90ad388` | feat(reports): add the report registry and the arrivals report           |
| `c913465` | docs(reports): settle the layout prop, sort fallback and report PDF      |
| `86a0a72` | feat(reports): add report layouts                                        |
| `1057a47` | docs(reports): settle export sort, typed values and downloads            |
| `a99280e` | feat(reports): export selected rows as PDF, CSV or Excel                 |
| `7b38e32` | docs(printing): plan the line grouper and amount in words                |
| `696ebf3` | feat(printing): add document switches                                    |
| `2752595` | fix(printing): merge only nightly rows                                   |
| `e9e5f21` | docs(printing): plan the Room column for ungrouped lines                 |
| `20a520e` | fix(printing): show a room column on ungrouped lines                     |
| `9eab5a1` | docs(reservations): plan how the booking summary builds its rows         |
| `d874515` | feat(reservations): print a booking summary                              |
| `d319aa0` | docs(reservations): plan no-shows on the booking summary                 |
| `e77527d` | fix(reservations): show no-shows on the booking summary                  |
| `e9bffe7` | docs(folio): plan the folio invoice rows, tax numbers and rooms          |
| `cf28d4b` | feat(folio): print a folio invoice                                       |
| `53b4cb8` | docs(city-ledger): plan the AR invoice lines and amount due              |
| `f05023a` | feat(city-ledger): apply document switches to the AR invoice             |
| `a9cc169` | docs(city-ledger): keep Amount due as the last AR invoice line           |
| `5280756` | docs(city-ledger): plan the credit note flow back from a reversal        |
| `ccf1036` | fix(city-ledger): explain how to credit a paid invoice                   |

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

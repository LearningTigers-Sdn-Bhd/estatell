# Rate plans

## Purpose

A rate plan is how room types are priced and sold: Basic Rate, Breakfast, Corporate, 3D2N Island Escape. A plan is defined once and linked to many room types.

## Data model

```
rate_plans              id, property_id, name, description, pricing_basis (PricingBasis),
                        extra_adult_amount, extra_child_amount, single_supplement,
                        parent_rate_plan_id (nullable), derivation (RateDerivation, nullable),
                        derivation_value (nullable, signed),
                        fixed_nights (nullable), package_unit (PackageUnit, nullable),
                        day_use_min_hours, day_use_max_hours (nullable, 1–24),
                        day_use_from, day_use_until (nullable time),
                        early_departure_policy (EarlyDeparturePolicy),
                        company_access (CompanyAccess, default Everyone),
                        company_at_desk, company_in_portal (booleans, default true),
                        position, archived_at, timestamps
rate_plan_room_types    id, rate_plan_id, room_type_id, timestamps
                        unique (rate_plan_id, room_type_id)
rate_plan_child_prices  id, rate_plan_id, property_age_group_id,
                        pricing (AgeBandPricing), value (nullable)
                        unique (rate_plan_id, property_age_group_id)
rate_plan_stay_discounts
                        id, rate_plan_id, min_nights, discount_type (StayDiscountType),
                        value, from_night, timestamps
                        unique (rate_plan_id, min_nights)
```

- `rate_plan_room_types` is a many-to-many link. It holds no prices. Prices live only in [daily rates](02-daily-rates-and-restrictions.md).
- The number of guests a price covers is the room type's `base_occupancy` ("Guests included"), not a plan column (see [Room types](../05-rooms/01-room-types.md)).
- There is no default plan flag. `position` sets the order.
- `early_departure_policy` is replaced by the property-level [early departure policy](../06-catalogues/06-room-revenue.md#policies). A later item makes it an optional per-plan override that follows the property by default. The column stays until then.

## Built first slice

Staff with `settings.manage` use Settings, Rates, Rate plans (`settings.rate-plans.*`, `RatePlanController`). Every write is an Action in `app/Actions/Rates/`.

- **Tables built:** `rate_hour_prices` (see [Day use prices](02-daily-rates-and-restrictions.md#day-use-prices)), `rate_plans`, `rate_plan_room_types`, `rate_plan_child_prices`, `rate_plan_stay_discounts`, `rate_plan_inclusions`. `rate_plans` has `parent_rate_plan_id`, `derivation` and `derivation_value` (see [Derived rate plans](04-derived-rate-plans.md)). It has `fixed_nights` and `package_unit` (see [Package prices](../09-packages/02-package-prices.md)). It has `company_access` (`CompanyAccess`, default Everyone, CHECK in everyone, all, selected), `company_at_desk` and `company_in_portal`, set on the Companies tab. `company_rate_plans` has `at_desk` and `in_portal`.
- **Companies tab built:** `settings.rate-plans.companies.edit|update`, `.store`, `.rows.update` and `.destroy`, the Actions `SetRatePlanCompanyAccess`, `AddRatePlanCompanies`, `UpdateRatePlanCompany` and `RemoveRatePlanCompany`, `RatePlanCompaniesData`. See [Who can use this rate](#who-can-use-this-rate).
- **Extra column:** `needs_child_price_review` (boolean) stores the "Check child prices" flag. [Age groups](../02-property/04-age-groups.md) sets it when a group is added. Saving the Children tab clears it.
- **Enums built:** `PricingBasis`, `EarlyDeparturePolicy`, `AgeBandPricing`, `StayDiscountType`, `RateDerivation`, `PackageUnit`. `PricingBasis::fits(SellUnit)` holds the sell unit table below.
- **Package plans** are created like any plan. The Package case fits both families. A database CHECK keeps `fixed_nights` (1 to 30) and `package_unit` set on a package and empty on every other plan. There is no `is_package` column, `pricing_basis = Package` says it.
- **New plan:** a Sheet with name, pricing basis and optional room types. Choosing Package adds Nights and Charged (per guest or per room). Room types are a multi-select combobox that shows how each is sold and disables one the basis cannot sell. The Room types tab keeps its checkbox list.
- **Editor:** a full page with `TabLinks`. Each tab is its own URL, controller method and page, and loads only its own data: Details (`settings.rate-plans.edit`), Room types (`settings.rate-plans.room-types.edit`), Pricing (`settings.rate-plans.pricing.edit`, a stay price grid for Package plans, see [Package prices](../09-packages/02-package-prices.md#ui), and [Daily rates](02-daily-rates-and-restrictions.md#ui) for the others) Children (`settings.rate-plans.child-prices.edit`, PerGuest plans and per-guest packages only, 404 otherwise) and Discounts (`settings.rate-plans.discounts.edit`, 404 for Package plans). Each tab is its own form. Leaving a tab with unsaved changes asks Cancel, Discard or Save (see [Form containers](../design-system/05-form-containers.md#unsaved-changes)). The Inclusions tab (`settings.rate-plans.inclusions.edit`) is open to every plan, see [Inclusions](../09-packages/01-inclusions.md). The Companies tab (`settings.rate-plans.companies.edit`, `settings.manage`, a 404 for Day use plans) sets who can use the rate, see [Who can use this rate](#who-can-use-this-rate). `early_departure_policy` has a column and a default (ChargeFull) but no field yet.
- **List:** one table with name, family, pricing, room types, status and a row menu (Edit, Create derived plan, Move up, Move down, Archive or Restore). **Create derived plan** shows only on an active plan that can be a parent (see [Derived rate plans](04-derived-rate-plans.md#create-derived-plan-quick-action)). A search box and a status filter (Active by default, Archived, All) filter the loaded list in the browser, and the choice is kept in `?status=`. Archived plans show in the same table, so there is no archived section. Reorder shows only on the Active filter with no search. Badges: status (`RatePlanStatus`), sells (tone only, no dot, from `PricingBasis::familyTone()`), pricing (icon and tone, from `PricingBasis::tone()`; a derived plan shows a branch icon and its "Follows" label), and "Check child prices". Room types show as up to 3 pills and one "+X" pill, whose tooltip lists the rest. A new plan opens on its Room types tab.
- **Guard built:** a room type keeps at least one active linked plan. It is checked on unlinking and archiving by `RatePlanCoverage`, and re-checked in the Action under a lock.
- **Package guard:** a package's nights cannot change while an active package follows it, and a package that follows another has its nights and unit locked (see [Derived packages](04-derived-rate-plans.md#derived-packages)).
- **Guards built for derived plans:** a parent is not archived while active plans follow it, a room type is not unlinked from a parent while a follower sells it, and a follower is not restored under an archived parent.
- **Guards not built yet:** Deleting a used plan and unlinking a room type with future reservations need reservations. No delete path exists yet, so plans are archived only.
- **Seeding:** `SeedRatePlans` runs in `SeedPropertyDefaults` and creates "Basic Rate", always PerRoom. The property type is not known to be dorm-only at this point, so a Bed room type has no plan until staff add a PerBed plan. The migration backfilled "Basic Rate" for existing properties.
- **Room type links:** `ReconcileRoomTypeRatePlans` links new and restored room types and re-checks links when the sell unit changes (see [Room types](../05-rooms/01-room-types.md)).
- **Permission:** no `rates.*` permission exists yet, so all routes use `settings.manage`.

## Day use plans

A plan with `pricing_basis = DayUse` sells a room for a number of hours on one day (see [Day use](../12-reservations/10-day-use.md)).

- **Columns.** `day_use_min_hours` and `day_use_max_hours` (1 to 24, min ≤ max) say which whole hours are sold: each hour from the minimum to the maximum has its own price. `day_use_from` and `day_use_until` are the optional **Sold between** window: a block must start at or after `from` and end at or before `until`. A CHECK keeps the hours set on a Day use plan and empty on every other plan, the two times both set or both empty, `from` before `until`, and no parent. The Form Request also needs the window to be at least the minimum hours long (no CHECK for that).
- **New plan Sheet.** "How is it priced?" adds **Day use**, which asks **Hours sold** (from, to). Room types sold per room only; a Bed room type is disabled with the reason.
- **Tabs.** Details adds **Hours sold** and **Sold between**. Room types as above. Pricing shows one row per hour count (see [Day use prices](02-daily-rates-and-restrictions.md#day-use-prices)). Inclusions is open (for example lunch). Children and Discounts are a 404, like a Package plan for Discounts. Portal is hidden: day use is front desk only.
- **Changing the hours** later only affects new bookings. Prices for hours outside the new range stay stored but are not offered.
- **Not derived.** A Day use plan cannot be a parent or follow a parent.
- **Never mapped** to a channel.
- Badge: "Day use" with a clock icon. `PricingBasis::tone()` and `familyTone()` are both Info.
- **Built:** `PricingBasis::DayUse` (sells Room types only). `strategy()` throws `LogicException`. `channelRefusal()` returns the front desk only reason, which `ChannelCapability` will read. `RatePlan::scopeNightly()` leaves Day use plans out of the add unit plan list, out of the room type cover rule (`RatePlanCoverage`, `ReconcileRoomTypeRatePlans`), and a nightly quote refuses one with `plan_not_nightly`. The Portal tab is a 404 for them and the portal never offers one (`PortalRatePlans`). Creating a Day use plan or a derived plan from one is refused.

## Enums

- `PricingBasis`: PerRoom, PerGuest, PerBed, Package, DayUse. It has `tone()` and `familyTone()` for the list badges.
- `RatePlanStatus`: Active, Archived. Derived from `archived_at`, used for the list badge and filter.
- `RateDerivation`: Percent, Amount. See [Derived rate plans](04-derived-rate-plans.md).
- `EarlyDeparturePolicy`: ChargeFull, ChargeUsedNights.
- `PackageUnit`: PerGuest, PerRoom. See [Package prices](../09-packages/02-package-prices.md).
- `AgeBandPricing`: Free, Percent, Fixed.
- `StayDiscountType`: Percent, Amount.
- `CompanyAccess`: Everyone, All, Selected (shown as "Only these companies"), with `label()` and `options()`. See [Who can use this rate](#who-can-use-this-rate).
- `CompanyRateMarket`: All, Local, International, with `label()` and `options()`. The market a plan is offered to. See [Market](#market). The company side is `CompanyMarket` (Local, International) in [Companies](../15-city-ledger/01-companies.md#enums).

## Who can use this rate

One setting on the rate plan owns it: **Who can use this rate** (`rate_plans.company_access`, `CompanyAccess`). It is set on the **Companies** tab of the plan editor. It replaces `portal_access`, the Portal tab and the switches on the company's Rates tab. Staff no longer visit two or three pages for one idea.

| Choice               | Front desk                                                             | Portal                                             |
| -------------------- | ---------------------------------------------------------------------- | -------------------------------------------------- |
| Everyone (default)   | Every booking. A normal plan.                                          | Never                                              |
| All companies        | Only when a company is picked on the booking, if Front desk is on      | Every company with a portal login, if Portal is on |
| Only these companies | Only when a listed company with Front desk on is picked on the booking | Only listed companies with Portal on               |

- **Columns:** `rate_plans.company_access` (CHECK in everyone, all, selected), `company_at_desk` and `company_in_portal` (booleans, default true, used by All companies; CHECK at least one is true when `company_access` is all). The rows of Only these companies are in `company_rate_plans` (`company_id`, `rate_plan_id`, `at_desk` default true, `in_portal` default false, CHECK `at_desk OR in_portal`).
- **Only derived plans** can be Only these companies, as with company links before. The radio is disabled on other plans, with the hint "Only a derived plan can be limited to some companies."
- **Day use plans** are front desk only and always Everyone. The Companies tab is a 404 for them. An archived plan cannot change.
- **Switching away** from Only these companies keeps the rows. They apply again if the choice is switched back.
- **Day use, channels and rates:** the choice never changes price. A plan priced for companies is still priced by `RateQuoteService`. Channel ingestion skips the front desk check (`fromChannel`).
- **Bookings already made** keep their plan and price when access changes.

### Market

A plan that is All companies or Only these companies can also be limited to local or international companies. This is a second filter on top of the access choice: both must pass, at the front desk and in the portal.

| Plan market             | Local company | International company | Company with no market |
| ----------------------- | ------------- | --------------------- | ---------------------- |
| Local and international | Yes           | Yes                   | Yes                    |
| Local only              | Yes           | No                    | No                     |
| International only      | No            | Yes                   | No                     |

- **Column:** `rate_plans.company_market` (`CompanyRateMarket`, default all, not null, CHECK in all, local, international). The company's market is `companies.market`, set by staff, never derived from the billing country.
- **Everyone plans** ignore the market. The radio is hidden for them and the value stays `all`.
- **Day use plans** are Everyone, so the market never applies.
- **Check:** `CompanyRateAccess` applies the market in `deskAllows` and `usableBy`. `CompanyRatePlans` and `PortalRatePlans` apply the same rule in their queries, so the lists and the checks agree.
- **Bookings already made** keep their plan and price when a plan's or a company's market changes. Changing the company on a booking re-checks each unit with `usableBy`, as for access.
- **Pickers:** the company combobox on the booking form and the Change company Dialog shows the market after the name, for example "Acme (Local)". The booking form gets `companyMarket` and `marketCompanyIds` on each plan option, so the browser filters the plan list without another request.
- **Existing plans** become `all` in the migration, so nothing changes on production until staff set a market on a company and a plan.

### Companies tab

The tab replaces the Portal tab in the same position. A radio group "Who can use this rate" holds the three choices.

- **All companies:** a "Where" radio: Front desk and portal, Front desk only, Portal only, and a Save button. Saving sets `company_at_desk` and `company_in_portal`.
- **Market** (shown for All companies and Only these companies): a radio below the choice: Local and international (default), Local companies only, International companies only. The hint reads: "A plan marked local or international is hidden from the other market, and from companies with no market set." For All companies it saves with the Save button. For Only these companies it saves as soon as it is picked.
- **Only these companies:** a table with the columns Company (name, with a link that opens that company's Rates tab), Login, Front desk, Portal and a ⋯ menu. Login is the company's portal login status: Active, Waiting, Temp password or —. Front desk and Portal are switches that save at once. Turning both off is refused: "Turn on at least one, or remove the company." The ⋯ menu has **Remove**.
- **Add companies** is a Dialog with a search box and a checklist of the Active companies not yet listed (name, type). Confirming adds them with both switches on and shows a toast.
- **Remove** is an AlertDialog "Remove {company} from this rate?" It says: "Staff won't see "{plan}" at the front desk for this company", then ", and the company can't book it in the portal" only when the row's Portal is on and the company has an Active login, then ". Bookings already made keep their price." The Remove button is destructive.
- **Removing the last company is allowed.** The empty state "No company has this rate yet." shows with **Add companies**. Nobody can use the rate until a company is added or the choice changes. The old refusals "Link at least one company first" and "This plan is Selected for the portal and needs a company" are gone.
- **Permissions:** the tab and its switches need `settings.manage`. Adding and removing companies also needs `company.manage`, as the Create derived plan Sheet does. Without it the buttons and menu are hidden.

### Actions

All in `app/Actions/Rates/`. Each returns null when done, or a refusal code when the state refuses, and writes nothing then. Each logs one activity entry.

| Action                     | Does                                                                                                                                                                                                                         | Log                                |
| -------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------- |
| `SetRatePlanCompanyAccess` | Saves `company_access`, `company_market` and, for All companies, the two switches. Refuses an archived or Day use plan, non derived plan with Only these companies, and both switches off. Everyone resets the market to all | `rate_plan.company_access_updated` |
| `AddRatePlanCompanies`     | Adds Active companies of the property to an Only these companies plan, both switches on. Skips companies already listed                                                                                                      | `rate_plan.companies_added`        |
| `UpdateRatePlanCompany`    | Saves `at_desk` and `in_portal` of one row. Refuses both off                                                                                                                                                                 | `rate_plan.company_updated`        |
| `RemoveRatePlanCompany`    | Deletes the row. Allowed for the last one                                                                                                                                                                                    | `rate_plan.company_removed`        |

`CompanyRateAccess` (a Service in `app/Services/Rates/`) answers the read side: `deskAllows($plan, $companyId)` for the front desk and `usableBy($plan, $companyId)` (any company the plan is open to, desk or portal) for the company change check. `PortalRatePlans` answers the portal side.

### Data change

The migration replaces `portal_access` with `company_access`, `company_at_desk` and `company_in_portal`, and adds `at_desk` and `in_portal` to `company_rate_plans`. It backfills in this order:

| Old state                        | New state                                                                                                                                                                                         |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Plan has company links           | Only these companies. Links get `at_desk` true, and `in_portal` true when the old `portal_access` was selected or all                                                                             |
| No links, `portal_access` hidden | Everyone                                                                                                                                                                                          |
| No links, `portal_access` all    | All companies, Front desk off and Portal on. The plan stays in the portal. It no longer shows at the front desk, where it showed for every booking; staff turn Front desk on if they want it back |

**Check the production and demo values before this migration runs there.** Two cases change what users see. A plan that was All for the portal and had links becomes Only these companies; its links keep the portal, but other companies lose it. A plan that was All for the portal with no links disappears from the front desk.

## Sell unit and pricing basis

A plan links only room types whose sell unit fits its pricing basis.

| Room type `sell_unit` | Allowed `pricing_basis`            |
| --------------------- | ---------------------------------- |
| Room                  | PerRoom, PerGuest, Package, DayUse |
| Bed                   | PerBed, Package                    |

Plans fall into two families: room plans and bed plans. Package is the only basis that can link both. The Form Request enforces the table with `Rule::enum(PricingBasis::class)->only(...)`.

## Rules

- `rates`, `package_prices` and `channel_rate_plan_mappings` exist only for a linked plan and room type pair.
- The first linked plan by `position` is preselected on the booking form and listed first on the public site.
- A booking can move only to a room type that is linked to its plan (see [Room and bed assignment](../18-front-desk/02-room-and-bed-assignment.md)).
- Property creation seeds one ordinary plan, "Basic Rate". New room types are linked to it when the sell unit fits (see [Seed property defaults](../02-property/03-seed-property-defaults.md)). Before go-live, onboarding can switch its basis to PerGuest or split it into "Basic Rate / Room" and "Basic Rate / Guest" ([Basic rate table](../17-onboarding/02-wizard-steps.md#basic-rate-table)). New room types then link to the seeded plan.
- Child prices apply to PerGuest plans and to packages charged per guest (`PricingBasis::usesChildPrices()`). Such a plan has one row per [age group](../02-property/04-age-groups.md). PerRoom plans and packages charged per room use `extra_child_amount`.
- A new PerGuest plan is pre-filled: the first group Free, every other group 50%.
- `Percent` is a percent of the plan's **1-adult** price for that night, never the party's per-adult share.
- `fixed_nights` and `package_unit` are described in [Package prices](../09-packages/02-package-prices.md).

## Guards

| Guard                                                                 | Message                                                        |
| --------------------------------------------------------------------- | -------------------------------------------------------------- |
| Every room type keeps at least one active linked plan                 | "6-bed Dorm would have no rate plan. Link another plan first." |
| A parent cannot be archived while active plans follow it              | "Breakfast and Corporate follow this plan."                    |
| A plan used by a reservation cannot be deleted. Archive it instead.   | "Used by 42 reservations. Archive instead."                    |
| A room type cannot be unlinked while future reservations use the pair | "3 upcoming bookings use this."                                |

Unlinking a room type with no future reservations deletes the pair's future `rates` rows. Past rows stay.

## Long-stay discounts

"Stay at least N nights, get X off", on one rate plan.

| Column          | Meaning                                                                                                |
| --------------- | ------------------------------------------------------------------------------------------------------ |
| `min_nights`    | 2 or more                                                                                              |
| `discount_type` | Percent (1–100) or Amount                                                                              |
| `value`         | Greater than 0                                                                                         |
| `from_night`    | 1 discounts every night. 2 keeps the first night at full price, and so on. Between 1 and `min_nights`. |

- The longest rule the stay qualifies for wins. A 5-night stay under "2+ nights 10%" and "4+ nights 20%" gets 20%.
- A night never goes below zero.
- `RateQuoteService` applies the rule after the pricing strategy, because a night's price depends on the stay length (see [Pricing strategies](03-pricing-strategies.md)).
- A Percent is taken off the night total (base, extra guests and children, and supplement) and rounded half up. An Amount is taken off per guest (adults and children) on PerGuest plans, per bed on PerBed plans and per room on PerRoom plans.
- Direct bookings only. Long-stay discounts are never sent to the channel manager.
- `SaveRatePlanStayDiscounts` saves the whole list in one transaction and matches rows by `min_nights`. It refuses an archived or Package plan. A save that changes nothing is not logged. The database checks `min_nights` ≥ 2, `from_night` from 1 to `min_nights`, Percent 1–100 and Amount above 0.
- Example: "3+ nights, 20% off from night 2". A 3-night stay at RM200 a night costs 200 + 160 + 160 = RM520.

### Price preview

The Discounts tab prices every linked room type with the rules on screen, before they are saved.

- The page posts the unsaved rows and the sample stay to `settings.rate-plans.discounts.preview`. `StayDiscountPreview` prices the stays and nothing is saved. The tab opens with a preview of the saved rules from the property's today.
- Rows that are not valid yet are left out, and the preview says how many.
- Two views, switched in the preview header without a request. **By night** is the default.
    - **By night:** one sample stay, a column per night, then Total. Each night shows its own price and discount, and the amount per unit. The sample stay's **Nights** (1–14) starts at the longest rule's `min_nights`, or 2 with no rules.
    - **By stay length:** a column for 1 night and each rule's `min_nights`, at most 4 columns. Each cell is the stay total, the discount and the nights in short form (`475.00 + 2 × 375.00`). Use it to compare tiers.
- Each room type is priced once for the longest stay either view needs, with `RateQuoteService::priceNights()`. Each stay then gets its own discount from `StayDiscount`, so the preview uses the same code as a booking quote.
- The amount per unit is per guest on a PerGuest plan with adults only, per bed on a PerBed plan, and per room otherwise.
- Sample stay: a start date and the guests. "Guests included" uses each room type's `base_occupancy` in adults. "Custom" sets adults, and children per age group on PerGuest plans, for every room type.
- A room type shows a reason instead of prices when a rate is missing ("No rate on 3 Nov 2026."), child prices are missing, or a custom party is larger than its `max_adults` or `max_children`. A PerBed plan books one bed per adult and skips the size check.

## UI

Three screens, one job each:

| Screen         | Job                                                                  | Stores prices                   |
| -------------- | -------------------------------------------------------------------- | ------------------------------- |
| Rooms          | Room types, rooms, beds. "N rate plans" opens the filtered calendar. | No                              |
| Rate plans     | What a plan is, which room types sell it, how it is priced           | No. Pricing only fills `rates`. |
| Rates calendar | Price and restrictions per room type, plan and date                  | Yes                             |

### Rate plans list

A table with name, family (Room or Bed), pricing summary ("Per room", "Basic Rate +RM50") and linked room types. Rows are reordered by drag, which sets `position`.

### New plan

A right-side Sheet with one short form, not a wizard: a name, how it is priced (Per room, Per guest, Per bed or Package; a package also asks for its nights and what it is charged on) and, optionally, the room types it sells. The family follows from the basis. Each room type is a checkbox item (name, description). It is disabled, with the reason, when the chosen basis cannot sell it, and changing the basis unticks room types that no longer fit. Saving opens the Details tab, or the Room types tab when none were chosen.

### Editor

A full page with tab links (one URL per tab): **Details · Room types · Pricing · Children · Discounts · Portal**. Background text goes in info tooltips, not paragraphs under each heading.

| Tab        | Content                                                                                                                                                        |
| ---------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Details    | Name, description (shown to guests and company users), extra guest charges, single supplement                                                                  |
| Room types | A checkbox per room type. Room types of the other family are shown disabled, with the reason in a tooltip.                                                     |
| Pricing    | Step 1: "Set prices directly" or "Follow another plan". Package plans show package prices instead, or follow a package with a percent.                         |
| Children   | PerGuest plans and per-guest packages only. One row per age group: Free, Percent or Fixed.                                                                     |
| Discounts  | Long-stay discounts and a price preview. "Add long-stay discount" sits in the table footer. Not for Package plans.                                             |
| Companies  | Who can use this rate: Everyone, All companies or Only these companies (derived plans only), with the front desk and portal switches. A 404 for Day use plans. |

- The Pricing and Discounts tabs use the full panel width (`panelWidth="full"`), not the 80% of the other tabs. Pricing needs it for the By day view (7 columns). On Discounts, the rules table is full width. Under it, a 3-column grid holds the sample stay (column 1) and the price preview (columns 2–3). Save is in the form footer under the preview.
- "Set prices directly" uses the shared [pricing form](02-daily-rates-and-restrictions.md#pricing-form). It writes `rates` rows and stores nothing on the plan.
- "Follow another plan" saves a derivation. It is disabled when no parent is eligible (see [Derived rate plans](04-derived-rate-plans.md#ui)).

## Lessons from wastays

- wastays forced one `sell_mode` on the hotel and every plan inherited it.
- Client feedback on the rate plan page: it "showed too much at once". wastays moved to a tabbed page with step-by-step pricing. estatell keeps that layout.
- A child's % age band was taken from the party's per-adult share, not the 1-adult price. 2 adults + a child of 8 on a Full Board plan came to 600 instead of 620.
- A nightly price came from three layers: `room_types.base_price`, `room_type_rate_plans.pricing_mode` (fixed, multiplier, offset) and daily `room_rates`. Staff could not tell which price won.
- Room types were attached to a plan from a separate "Assign room rate" sheet, and the plan page priced one room type at a time.
- `room_type_rate_plans.primary_plan` added a per-room-type default that staff did not need.

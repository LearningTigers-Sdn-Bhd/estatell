<?php

namespace App\Http\Controllers\Reservations;

use App\Data\CityLedger\ArInvoiceData;
use App\Data\Folio\RoutingRulesData;
use App\Data\Reservations\ReservationActivityData;
use App\Data\Reservations\ReservationAddOnData;
use App\Data\Reservations\ReservationListItemData;
use App\Data\Reservations\ReservationStatusCountData;
use App\Data\Reservations\ReservationUnitData;
use App\Data\Reservations\ReservationUnitOptionData;
use App\Enums\BillingMode;
use App\Enums\Gender;
use App\Enums\Locale;
use App\Enums\PermissionEnum;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Http\Controllers\Controller;
use App\Models\ArInvoice;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\UnitAddOn;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\CityLedger\ArInvoiceDetail;
use App\Services\CityLedger\CompanyOptions;
use App\Services\Folio\AddOnBilling;
use App\Services\Folio\FolioStatement;
use App\Services\Folio\FolioVoucher;
use App\Services\Folio\RoutingRuleList;
use App\Services\FrontDesk\GroupCheckOutPreview;
use App\Services\Guests\CountryOptions;
use App\Services\Payments\PaymentReceipt;
use App\Services\Reservations\ReservationActivityLog;
use App\Services\Reservations\ReservationDirectory;
use App\Services\Reservations\ReservationFormOptions;
use App\Services\Reservations\ReservationGuestRows;
use App\Services\Reservations\ReservationOverview;
use App\Services\Reservations\ReservationRoomBox;
use App\Services\Reservations\UnitActionMenu;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

class ReservationController extends Controller
{
    /**
     * List the property's reservations with status tabs, search, filters and sorting in the URL.
     */
    public function index(Request $request, Property $property, ReservationDirectory $directory, ReservationRoomBox $roomBox): Response
    {
        $reservations = $directory->paginate($property, $request);
        $page = $reservations->getCollection();
        $settings = $property->settings;
        $boxes = $roomBox->forReservations($page, (bool) $settings?->auto_assign_rooms);
        $repeats = array_flip($directory->repeatReservationIds($property, $page));
        $canViewGuest = $request->user()->can(PermissionEnum::GuestsView->value);

        $reservations->setCollection($page->map(fn (Reservation $reservation): ReservationListItemData => ReservationListItemData::fromModel(
            $reservation,
            $boxes[$reservation->id],
            isset($repeats[$reservation->id]),
            $canViewGuest,
        )));

        return Inertia::render('reservations/index', [
            'reservations' => ReservationListItemData::collect($reservations, PaginatedDataCollection::class),
            'statusCounts' => $this->statusCounts($directory->statusCounts($property, $request)),
            'filters' => [
                'search' => $request->string('filter.search')->toString(),
                'status' => $request->string('filter.status')->toString(),
                'source' => $request->string('filter.source')->toString(),
                'stay' => $directory->stay($property, $request),
            ],
            'sort' => $request->string('sort', 'arrival')->toString(),
            'sorts' => $this->sortOptions(),
            'sources' => ReservationSource::options(),
            'currency' => $settings?->currency,
            'hasReservations' => $property->reservations()->exists(),
            'canManage' => $request->user()->can(PermissionEnum::ReservationsManage->value),
        ]);
    }

    /**
     * Reservation page, Units tab. `?unit=` picks the unit panel, and an unknown unit falls back to the first.
     */
    public function show(Request $request, Property $property, Reservation $reservation, ReservationActivityLog $activityLog, UnitActionMenu $actionMenu): Response
    {
        return $this->renderTab($request->user(), $property, $reservation, 'units', function (array $units, Collection $unitModels) use ($request, $reservation, $activityLog, $actionMenu): array {
            $unitId = $request->integer('unit');
            $selected = $unitModels->firstWhere('id', $unitId) ?? $unitModels->first();

            if ($selected === null) {
                return ['unit' => null];
            }

            $selected->load(['ratePlan', 'childAges', 'guests.guest', 'nights' => fn ($nights) => $nights->orderBy('date'), 'nights.room', 'nights.bed', 'nights.ratePlan']);
            $override = $activityLog->priceOverride($reservation, $selected->id);

            $canChangePrice = $request->user()->can(PermissionEnum::ReservationsChangePrice->value)
                && in_array($selected->status, [UnitStatus::Confirmed, UnitStatus::CheckedIn, UnitStatus::CheckedOut], true);
            $charged = FolioLine::query()
                ->whereIn('idempotency_key', $selected->nights->map(fn (UnitNight $night): string => 'night:'.$night->id)->all())
                ->pluck('source_id')
                ->map(intval(...))
                ->all();

            return ['unit' => ReservationUnitData::fromModel($selected, $request->user()->can(PermissionEnum::GuestsView->value), $override['reason'] ?? null, $override['by'] ?? null, $actionMenu->for($request->user(), $selected), $canChangePrice, $charged)];
        });
    }

    /**
     * Reservation page, Guests tab.
     */
    public function guests(Request $request, Property $property, Reservation $reservation, ReservationGuestRows $guestRows): Response
    {
        $canManageGuests = $request->user()->can(PermissionEnum::GuestsManage->value);

        return $this->renderTab($request->user(), $property, $reservation, 'guests', fn (array $units): array => [
            'guests' => $guestRows->rows($reservation, $units, $request->user()->can(PermissionEnum::GuestsView->value), $request->user()->can(PermissionEnum::RegistrationCardSign->value), $request->user()->can(PermissionEnum::FolioTransfer->value)),
            'canManageGuests' => $canManageGuests,
            'countries' => $canManageGuests ? app(CountryOptions::class)->options() : [],
            'genders' => Gender::options(),
            'locales' => Locale::options(),
        ]);
    }

    /**
     * Reservation page, Add-ons tab. Rows are ordered by unit, then service date.
     */
    public function addOns(Request $request, Property $property, Reservation $reservation, AddOnBilling $billing): Response
    {
        return $this->renderTab($request->user(), $property, $reservation, 'add-ons', function (array $units, Collection $models) use ($reservation, $billing): array {
            $labels = collect($units)->mapWithKeys(fn (ReservationUnitOptionData $unit): array => [$unit->id => $unit->label]);
            $addOns = UnitAddOn::query()
                ->whereIn('reservation_unit_id', $labels->keys()->all())
                ->with(['chargeItem.transactionCode', 'folioLine'])
                ->orderBy('reservation_unit_id')
                ->orderBy('service_date')
                ->orderBy('id')
                ->get();
            $where = $billing->for($reservation, $addOns, $models, $labels->all());

            return [
                'addOns' => $addOns
                    ->map(fn (UnitAddOn $addOn): ReservationAddOnData => ReservationAddOnData::fromModel($addOn, $labels[$addOn->reservation_unit_id], $where[$addOn->id]['folio'], $where[$addOn->id]['routedTo']))
                    ->all(),
            ];
        });
    }

    /**
     * Reservation page, Folio tab. `?folio=` picks the folio (an id, or `u{unit id}` for a unit with no folio yet).
     * `routingRules` is an optional prop for the Routing rules Sheet, sent only to a user with `folio.transfer`. `creditInvoice` is an optional prop with the AR invoice page data of a settled folio, read when its credit note Dialog or void Dialog opens and null without the credit note permission or the void and reopen permissions.
     */
    public function folio(Request $request, Property $property, Reservation $reservation, FolioStatement $statement, RoutingRuleList $routingRuleList, ArInvoiceDetail $invoiceDetail): Response
    {
        $canManageRouting = $request->user()->can(PermissionEnum::FolioTransfer->value);

        return $this->renderTab($request->user(), $property, $reservation, 'folio', function (array $units) use ($request, $property, $reservation, $statement, $routingRuleList, $canManageRouting, $invoiceDetail): array {
            $folioProps = $statement->build($property, $reservation, $units, $request->string('folio')->toString(), [
                'postCharge' => $request->user()->can(PermissionEnum::FolioPostCharge->value),
                'reverse' => $request->user()->can(PermissionEnum::FolioReverse->value),
                'transfer' => $canManageRouting,
                'discount' => $request->user()->can(PermissionEnum::FolioDiscount->value),
                'close' => $request->user()->can(PermissionEnum::FolioClose->value),
                'recordPayment' => $request->user()->can(PermissionEnum::PaymentRecord->value),
                'refund' => $request->user()->can(PermissionEnum::PaymentRefund->value),
                'printVoucher' => $request->user()->can(PermissionEnum::FolioPrintVoucher->value),
                'settleCityLedger' => $request->user()->can(PermissionEnum::CityLedgerInvoice->value),
                'changePrice' => $request->user()->can(PermissionEnum::ReservationsChangePrice->value),
            ], $request->user());

            return [
                ...$folioProps,
                'voucher' => [
                    'kinds' => array_values(array_filter(TransactionKind::options(), fn (array $kind): bool => $kind['value'] !== TransactionKind::Payment->value)),
                    'maxLines' => FolioVoucher::MAX_LINES,
                ],
                'canManageRouting' => $canManageRouting,
                'routingRules' => Inertia::optional(fn (): ?RoutingRulesData => $canManageRouting ? $routingRuleList->build($reservation, $units) : null),
                'creditInvoice' => Inertia::optional(function () use ($request, $property, $folioProps, $invoiceDetail): ?ArInvoiceData {
                    $folio = $folioProps['folio'] ?? null;
                    $invoice = $folio?->canCreditArInvoice || $folio?->canVoidAndReopen ? ArInvoice::query()->where('property_id', $property->id)->find($folio->arInvoiceId) : null;

                    return $invoice === null ? null : $invoiceDetail->build($invoice, $request->user());
                }),
            ];
        });
    }

    /**
     * Reservation page, Activity tab: the log, newest first, 25 to a page.
     */
    public function activity(Request $request, Property $property, Reservation $reservation, ReservationActivityLog $activityLog): Response
    {
        return $this->renderTab($request->user(), $property, $reservation, 'activity', fn (): array => [
            'activity' => ReservationActivityData::collect($activityLog->paginate($reservation), PaginatedDataCollection::class),
        ]);
    }

    /**
     * Each tab has its own URL, so the open tab survives a refresh. Every tab gets the header, the summary and the unit
     * list. Only the open tab's own data is read and sent. The form option lists are optional props, read when an
     * overlay opens.
     *
     * @param  'units'|'guests'|'add-ons'|'folio'|'activity'  $tab
     * @param  callable(list<ReservationUnitOptionData>, Collection<int, ReservationUnit>): array<string, mixed>  $tabProps
     */
    private function renderTab(User $user, Property $property, Reservation $reservation, string $tab, callable $tabProps): Response
    {
        $canViewGuest = $user->can(PermissionEnum::GuestsView->value);
        $reservation->load(['booker', 'creator', 'company']);
        $overview = app(ReservationOverview::class)->build($reservation, $canViewGuest);
        $options = app(ReservationFormOptions::class);

        return Inertia::render('reservations/show', [
            'reservation' => $overview['reservation'],
            'units' => $overview['units'],
            'tab' => $tab,
            'autoAssignRooms' => (bool) $property->settings?->auto_assign_rooms,
            'currency' => $property->settings?->currency,
            'canViewGuest' => $canViewGuest,
            'canManage' => $user->can(PermissionEnum::ReservationsManage->value),
            'canCheckIn' => $user->can(PermissionEnum::FrontDeskCheckIn->value),
            'canCheckOutAll' => $user->can(PermissionEnum::FrontDeskCheckOut->value) && app(GroupCheckOutPreview::class)->isOffered($overview['models']),
            'receiptPaymentIds' => $user->can(PermissionEnum::PaymentRecord->value) ? app(PaymentReceipt::class)->paymentIdsFor($reservation) : [],
            'canWaiveFees' => $user->can(PermissionEnum::FrontDeskWaiveFees->value),
            'canOverrideRestrictions' => $user->can(PermissionEnum::RatesOverrideRestrictions->value),
            'billingModes' => BillingMode::options(),
            'canViewCompany' => $user->can(PermissionEnum::CompanyView->value),
            'canOverrideCredit' => $user->can(PermissionEnum::CompanyCreditOverride->value),
            'companies' => Inertia::optional(fn () => $user->can(PermissionEnum::CompanyView->value) ? app(CompanyOptions::class)->active($property) : []),
            'addUnitOptions' => Inertia::optional(fn () => $options->addUnit($property)),
            'chargeItems' => Inertia::optional(fn () => $options->chargeItems($property)),
            'chargeCodes' => Inertia::optional(fn () => $options->chargeCodes($property)),
            'folioDiscounts' => Inertia::optional(fn () => $options->folioDiscounts($property, $user->can(PermissionEnum::FolioDiscountOpen->value))),
            'paymentOptions' => Inertia::optional(fn () => $options->payments($property)),
            ...$tabProps($overview['units'], $overview['models']),
        ]);
    }

    /**
     * All, then one tab per status, each with its count.
     *
     * @param  array<string, int>  $counts
     * @return list<ReservationStatusCountData>
     */
    private function statusCounts(array $counts): array
    {
        return [
            new ReservationStatusCountData('', __('All'), array_sum($counts)),
            ...array_map(
                fn (ReservationStatus $status): ReservationStatusCountData => new ReservationStatusCountData($status->value, $status->label(), $counts[$status->value] ?? 0),
                ReservationStatus::cases(),
            ),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function sortOptions(): array
    {
        return [
            ['value' => 'arrival', 'label' => __('Arrival, earliest first')],
            ['value' => '-arrival', 'label' => __('Arrival, latest first')],
            ['value' => 'departure', 'label' => __('Departure, earliest first')],
            ['value' => '-departure', 'label' => __('Departure, latest first')],
            ['value' => 'reference', 'label' => __('Reference, A to Z')],
            ['value' => '-reference', 'label' => __('Reference, Z to A')],
            ['value' => 'total', 'label' => __('Total, lowest first')],
            ['value' => '-total', 'label' => __('Total, highest first')],
            ['value' => 'balance', 'label' => __('Balance, lowest first')],
            ['value' => '-balance', 'label' => __('Balance, highest first')],
            ['value' => 'booked', 'label' => __('Booked, oldest first')],
            ['value' => '-booked', 'label' => __('Booked, newest first')],
        ];
    }
}

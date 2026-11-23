<?php

namespace App\Http\Controllers\Events;

use App\Actions\Events\CancelEvent;
use App\Actions\Events\ConfirmEvent;
use App\Actions\Events\CreateEvent;
use App\Actions\Events\UpdateEvent;
use App\Data\CityLedger\ArInvoiceData;
use App\Data\Events\EventData;
use App\Data\Events\EventRowData;
use App\Data\Property\CurrencyData;
use App\Enums\EventStatus;
use App\Enums\PermissionEnum;
use App\Enums\TransactionKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Events\EventRequest;
use App\Models\ArInvoice;
use App\Models\Event;
use App\Models\Property;
use App\Models\User;
use App\Services\CityLedger\ArInvoiceDetail;
use App\Services\CityLedger\CompanyOptions;
use App\Services\Events\EventDirectory;
use App\Services\Folio\FolioStatement;
use App\Services\Folio\FolioVoucher;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Platform\Impersonation;
use App\Services\Property\PropertyToday;
use App\Services\Reservations\ReservationFormOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

/**
 * The Events list, New event, the event page and its status actions. See
 * docs/33-facilities/03-events-and-event-folios.md.
 */
class EventController extends Controller
{
    public function index(Request $request, Property $property, EventDirectory $directory): Response
    {
        $user = $request->user();

        return Inertia::render('events/index', [
            'events' => EventRowData::collect($directory->paginate($property, $request), PaginatedDataCollection::class),
            'filters' => [
                'search' => $request->string('filter.search')->toString(),
                'status' => $request->string('filter.status')->toString(),
                'period' => $directory->period($request),
            ],
            'statuses' => EventStatus::options(),
            'periods' => $directory->periodOptions(),
            'canManage' => $user->can(PermissionEnum::EventsManage->value),
            'currency' => CurrencyData::forProperty($property),
        ]);
    }

    /**
     * New event: a full page, so facility booking lines can join it. See
     * docs/33-facilities/03-events-and-event-folios.md#new-event-page.
     */
    public function create(Request $request, Property $property, CompanyOptions $companies): Response
    {
        $user = $request->user();
        $canViewCompany = $user->can(PermissionEnum::CompanyView->value);

        return Inertia::render('events/create', [
            'canAddGuest' => $user->can(PermissionEnum::GuestsManage->value),
            'canViewCompany' => $canViewCompany,
            'companies' => $canViewCompany ? $companies->active($property) : [],
            'statuses' => EventStatus::newEventOptions(),
            'defaultDate' => (app(CurrentBusinessDate::class)->date($property) ?? app(PropertyToday::class)->date($property))->toDateString(),
        ]);
    }

    public function store(EventRequest $request, Property $property, CreateEvent $createEvent): RedirectResponse
    {
        $event = $createEvent($property, $request->user(), $request->eventAttributes());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Event :number created.', ['number' => $event->number])]);

        return to_route('events.show', ['property' => $property, 'event' => $event]);
    }

    /**
     * Event page, Folio tab: the event folio with the same panel as the reservation folio tab, without routing,
     * moving charges, manual entry or e-invoicing.
     */
    public function show(Request $request, Property $property, Event $event, FolioStatement $statement, ArInvoiceDetail $invoiceDetail, ReservationFormOptions $options): Response
    {
        $user = $request->user();
        $property->loadMissing('settings');
        $folioProps = $statement->buildForEvent($property, $event, [
            'postCharge' => $user->can(PermissionEnum::FolioPostCharge->value),
            'reverse' => $user->can(PermissionEnum::FolioReverse->value),
            'discount' => $user->can(PermissionEnum::FolioDiscount->value),
            'close' => $user->can(PermissionEnum::FolioClose->value),
            'recordPayment' => $user->can(PermissionEnum::PaymentRecord->value),
            'refund' => app(Impersonation::class)->mayRefund($user),
            'printVoucher' => $user->can(PermissionEnum::FolioPrintVoucher->value),
            'settleCityLedger' => $user->can(PermissionEnum::CityLedgerInvoice->value),
            'viewManual' => $user->can(PermissionEnum::FolioViewManualEntries->value),
        ], $user);

        return $this->renderTab($user, $property, $event, 'folio', [
            ...$folioProps,
            'voucher' => [
                'kinds' => array_values(array_filter(TransactionKind::options(), fn (array $kind): bool => $kind['value'] !== TransactionKind::Payment->value)),
                'maxLines' => FolioVoucher::MAX_LINES,
            ],
            'chargeItems' => Inertia::optional(fn () => $options->chargeItems($property)),
            'chargeCodes' => Inertia::optional(fn () => $options->chargeCodes($property)),
            'folioDiscounts' => Inertia::optional(fn () => $options->folioDiscounts($property, $user->can(PermissionEnum::FolioDiscountOpen->value))),
            'paymentOptions' => Inertia::optional(fn () => $options->payments($property)),
            'creditInvoice' => Inertia::optional(function () use ($user, $property, $folioProps, $invoiceDetail): ?ArInvoiceData {
                $folio = $folioProps['folio'] ?? null;
                $invoice = $folio?->canCreditArInvoice || $folio?->canVoidAndReopen ? ArInvoice::query()->where('property_id', $property->id)->find($folio->arInvoiceId) : null;

                return $invoice === null ? null : $invoiceDetail->build($invoice, $user);
            }),
        ]);
    }

    /**
     * Event page, Bookings tab. Facility bookings come with the facility bookings item; until then it is empty.
     */
    public function bookings(Request $request, Property $property, Event $event): Response
    {
        return $this->renderTab($request->user(), $property, $event, 'bookings', []);
    }

    public function update(EventRequest $request, Property $property, Event $event, UpdateEvent $updateEvent): RedirectResponse
    {
        $updated = $updateEvent($request->user(), $event, $request->eventAttributes());

        Inertia::flash('toast', $updated
            ? ['type' => 'success', 'message' => __('Event saved.')]
            : ['type' => 'error', 'message' => __('A completed or cancelled event cannot be edited.')]);

        return back(fallback: route('events.show', ['property' => $property, 'event' => $event]));
    }

    public function confirm(Request $request, Property $property, Event $event, ConfirmEvent $confirmEvent): RedirectResponse
    {
        $confirmed = $confirmEvent($request->user(), $event);

        Inertia::flash('toast', $confirmed
            ? ['type' => 'success', 'message' => __('Event :number confirmed.', ['number' => $event->number])]
            : ['type' => 'error', 'message' => __('Only a tentative event can be confirmed.')]);

        return back(fallback: route('events.show', ['property' => $property, 'event' => $event]));
    }

    public function cancel(Request $request, Property $property, Event $event, CancelEvent $cancelEvent): RedirectResponse
    {
        $cancelled = $cancelEvent($request->user(), $event);

        Inertia::flash('toast', $cancelled
            ? ['type' => 'success', 'message' => __('Event :number cancelled. Refund the deposit or post a fee, then close the folio.', ['number' => $event->number])]
            : ['type' => 'error', 'message' => __('This event is already completed or cancelled.')]);

        return back(fallback: route('events.show', ['property' => $property, 'event' => $event]));
    }

    /**
     * The props every tab shares: the header, the summary and the Edit event Dialog.
     *
     * @param  array<string, mixed>  $tabProps
     */
    private function renderTab(User $user, Property $property, Event $event, string $tab, array $tabProps): Response
    {
        $event->load(['payerGuest', 'payerCompany:id,name,contact_phone']);

        return Inertia::render($tab === 'folio' ? 'events/show' : 'events/bookings', [
            'event' => EventData::fromModel($event, $user->can(PermissionEnum::EventsManage->value)),
            'tab' => $tab,
            ...$this->editProps($user, $property),
            'currency' => CurrencyData::forProperty($property),
            ...$tabProps,
        ]);
    }

    /**
     * What the Edit event Dialog needs.
     *
     * @return array<string, mixed>
     */
    private function editProps(User $user, Property $property): array
    {
        $canViewCompany = $user->can(PermissionEnum::CompanyView->value);

        return [
            'canViewCompany' => $canViewCompany,
            'companies' => Inertia::optional(fn () => $canViewCompany ? app(CompanyOptions::class)->active($property) : []),
        ];
    }
}

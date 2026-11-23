<?php

namespace App\Http\Controllers\Events;

use App\Actions\Events\CancelEvent;
use App\Actions\Events\ConfirmEvent;
use App\Actions\Events\CreateEvent;
use App\Actions\Events\UpdateEvent;
use App\Actions\Facilities\FacilityBookingResult;
use App\Data\CityLedger\ArInvoiceData;
use App\Data\Events\EventData;
use App\Data\Events\EventRowData;
use App\Data\Facilities\FacilityBookingRowData;
use App\Data\Facilities\FacilityOptionData;
use App\Data\Property\CurrencyData;
use App\Enums\EventStatus;
use App\Enums\FacilityBookingStatus;
use App\Enums\Gender;
use App\Enums\IdentityDocumentType;
use App\Enums\Locale;
use App\Enums\PermissionEnum;
use App\Enums\TransactionKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Events\EventRequest;
use App\Http\Requests\Events\NewEventRequest;
use App\Http\Requests\Events\QuoteEventLinesRequest;
use App\Models\ArInvoice;
use App\Models\Event;
use App\Models\Facility;
use App\Models\FacilityBooking;
use App\Models\Property;
use App\Models\User;
use App\Services\CityLedger\ArInvoiceDetail;
use App\Services\CityLedger\CompanyOptions;
use App\Services\Events\EventBookingSummary;
use App\Services\Events\EventDirectory;
use App\Services\Facilities\EventLinesQuote;
use App\Services\Facilities\FacilityBookingCharge;
use App\Services\Facilities\FacilityBookingRefusals;
use App\Services\Folio\FolioStatement;
use App\Services\Folio\FolioVoucher;
use App\Services\Guests\CountryOptions;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Platform\Impersonation;
use App\Services\Property\PrintedPdf;
use App\Services\Property\PropertyToday;
use App\Services\Reservations\ReservationFormOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as PdfResponse;
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
     * New event: a full page with its facility lines. Staff without `facilities.book` get no lines. See
     * docs/33-facilities/03-events-and-event-folios.md#new-event-page.
     */
    public function create(NewEventRequest $request, Property $property, CompanyOptions $companies): Response
    {
        $user = $request->user();
        $canViewCompany = $user->can(PermissionEnum::CompanyView->value);
        $canBook = $user->can(PermissionEnum::FacilitiesBook->value);

        $canManageGuests = $user->can(PermissionEnum::GuestsManage->value);
        $facilities = $canBook ? $this->facilityOptions($property) : [];
        $prefill = $request->prefill();
        $businessDate = app(CurrentBusinessDate::class)->date($property);

        return Inertia::render('events/create', [
            'canManageGuests' => $canManageGuests,
            'canAddIdentity' => $canManageGuests && $user->can(PermissionEnum::GuestsViewDocuments->value),
            'identityDocumentTypes' => IdentityDocumentType::options(),
            'countries' => $canManageGuests ? app(CountryOptions::class)->options() : [],
            'defaultNationalityCode' => $property->country_code ?? 'MY',
            'genders' => Gender::options(),
            'locales' => Locale::options(),
            'canViewCompany' => $canViewCompany,
            'companies' => $canViewCompany ? $companies->active($property) : [],
            'statuses' => EventStatus::newEventOptions(),
            'defaultDate' => $prefill['date'] ?? ($businessDate ?? app(PropertyToday::class)->date($property))->toDateString(),
            'businessDate' => $businessDate?->toDateString(),
            'canBook' => $canBook,
            'facilities' => $facilities,
            'defaultFacilityId' => collect($facilities)->contains(fn (FacilityOptionData $facility): bool => $facility->id === $prefill['facility']) ? $prefill['facility'] : null,
            'currency' => CurrencyData::forProperty($property),
        ]);
    }

    /**
     * Create the event with its facility lines. A refused line goes back to the page as an error on that line.
     */
    public function store(EventRequest $request, Property $property, CreateEvent $createEvent, FacilityBookingRefusals $refusals): RedirectResponse
    {
        $bookings = $request->bookingInputs();
        $result = $createEvent->withBookings($property, $request->user(), $request->eventAttributes(), $bookings);

        if ($result->guestRefusal !== null) {
            return back()->withErrors(['guest.full_name' => __('This guest was merged or removed. Search for the guest again.')]);
        }

        if ($result->isRefused()) {
            return back()->withErrors($refusals->lineErrors((int) $result->line, $result->refusal));
        }

        $event = $result->event;

        Inertia::flash('toast', ['type' => 'success', 'message' => $bookings === []
            ? __('Event :number created.', ['number' => $event?->number])
            : trans_choice('Event :number created with :count facility booking.|Event :number created with :count facility bookings.', count($bookings), ['number' => $event?->number])]);

        return to_route('events.show', ['property' => $property, 'event' => $event]);
    }

    /**
     * The New event page's quote of its facility lines, with the totals for the summary rail.
     */
    public function quote(QuoteEventLinesRequest $request, Property $property, EventLinesQuote $quote): JsonResponse
    {
        return response()->json($quote->quote($request->draftEvent($property), $request->lines()));
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
     * The event's booking summary as an A4 PDF shown inline. It is rendered on demand and stores nothing. `?prices=0`
     * prints it without prices; an event with no company payer ignores that.
     */
    public function bookingSummary(Request $request, Property $property, Event $event, EventBookingSummary $summary, PrintedPdf $printedPdf): PdfResponse
    {
        return $printedPdf->make($property, 'pdf.booking-summary', [
            'summary' => $summary->for($property, $event, $request->boolean('prices', true)),
            'settings' => $property->printSetting,
        ])->stream('booking-summary-'.$event->number.'.pdf');
    }

    /**
     * Event page, Bookings tab: the event's facility bookings, Cancelled ones last, and the facilities the Book
     * facility Sheet offers. See docs/33-facilities/04-facility-bookings.md#event-bookings-tab.
     */
    public function bookings(Request $request, Property $property, Event $event, FacilityBookingCharge $charge): Response
    {
        $user = $request->user();
        $canBook = $user->can(PermissionEnum::FacilitiesBook->value)
            && in_array($event->status, [EventStatus::Tentative, EventStatus::Confirmed], true);
        $bookings = FacilityBooking::query()
            ->where('event_id', $event->id)
            ->with('facility')
            ->orderByRaw('status = ? ', [FacilityBookingStatus::Cancelled->value])
            ->ordered()
            ->get();

        $posted = $charge->postedIds($bookings);
        $canPost = $user->can(PermissionEnum::FolioPostCharge->value);

        return $this->renderTab($user, $property, $event, 'bookings', [
            'bookings' => $bookings->map(fn (FacilityBooking $booking): FacilityBookingRowData => FacilityBookingRowData::fromModel($booking, $canBook, null, isset($posted[$booking->id]), $canPost))->values()->all(),
            'canBook' => $canBook,
            'facilities' => $canBook ? $this->facilityOptions($property) : [],
        ]);
    }

    public function update(EventRequest $request, Property $property, Event $event, UpdateEvent $updateEvent): RedirectResponse
    {
        $result = $updateEvent($request->user(), $event, $request->eventAttributes());

        if ($result->refusal === FacilityBookingResult::REFUSAL_OUTSIDE_EVENT) {
            return back()->withErrors(['starts_on' => __('A facility booking is outside these dates. Move or cancel it first.')]);
        }

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => __('A completed or cancelled event cannot be edited.')]
            : ['type' => 'success', 'message' => __('Event saved.')]);

        return back(fallback: route('events.show', ['property' => $property, 'event' => $event]));
    }

    public function confirm(Request $request, Property $property, Event $event, ConfirmEvent $confirmEvent, FacilityBookingRefusals $refusals): RedirectResponse
    {
        $result = $confirmEvent($request->user(), $event);

        Inertia::flash('toast', $result->isRefused()
            ? ['type' => 'error', 'message' => $result->refusal === FacilityBookingResult::REFUSAL_EVENT_NOT_TENTATIVE
                ? $refusals->message($result)
                : __('The event cannot be confirmed. :reason', ['reason' => $refusals->message($result)])]
            : ['type' => 'success', 'message' => __('Event :number confirmed.', ['number' => $event->number])]);

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
     * The active facilities, in their settings order, for the Book facility Sheet and the New event lines.
     *
     * @return list<FacilityOptionData>
     */
    private function facilityOptions(Property $property): array
    {
        return array_values(Facility::query()
            ->where('property_id', $property->id)
            ->where('active', true)
            ->with(['chargeItem', 'sessions' => fn ($query) => $query->ordered()])
            ->ordered()
            ->get()
            ->map(fn (Facility $facility): FacilityOptionData => FacilityOptionData::fromModel($facility))
            ->all());
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

<?php

namespace App\Http\Controllers\Guests;

use App\Actions\Guests\BlacklistGuest;
use App\Actions\Guests\CreateGuest;
use App\Actions\Guests\SetVip;
use App\Actions\Guests\UnblacklistGuest;
use App\Actions\Guests\UpdateGuest;
use App\Data\Guests\GuestData;
use App\Data\Guests\GuestIdentityDocumentData;
use App\Data\Guests\GuestMatchData;
use App\Data\Guests\GuestNoteData;
use App\Data\Guests\GuestOptionData;
use App\Data\Guests\GuestRowData;
use App\Data\Guests\GuestStayData;
use App\Data\Guests\GuestStaySummaryData;
use App\Data\Guests\TagData;
use App\Data\Property\CurrencyData;
use App\Enums\Gender;
use App\Enums\GuestBulkAction;
use App\Enums\IdentityDocumentType;
use App\Enums\Locale;
use App\Enums\PermissionEnum;
use App\Enums\ReportKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Guests\BlacklistGuestRequest;
use App\Http\Requests\Guests\BulkUpdateGuestsRequest;
use App\Http\Requests\Guests\GuestRequest;
use App\Models\BookingSource;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\GuestNote;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\Tag;
use App\Models\User;
use App\Services\Guests\CountryOptions;
use App\Services\Guests\GuestDirectory;
use App\Services\Guests\GuestMatcher;
use App\Services\Guests\GuestStayHistory;
use App\Services\Property\PropertyToday;
use App\Services\Reports\ReportLayoutResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

class GuestController extends Controller
{
    /**
     * List the property's guests with search, filters and sorting in the URL.
     */
    public function index(Request $request, Property $property, GuestDirectory $guestDirectory, ReportLayoutResolver $layoutResolver): Response
    {
        $canManage = $request->user()->can(PermissionEnum::GuestsManage->value);
        $layout = $layoutResolver->resolve($property, ReportKey::Guests);
        $sort = $guestDirectory->visibleSort($request->string('sort', 'full_name')->toString(), $layout->visibleKeys());

        return Inertia::render('guests/index', [
            'guests' => GuestRowData::collect($guestDirectory->paginate($property, $request, $request->user()->can(PermissionEnum::GuestsViewDocuments->value), $sort), PaginatedDataCollection::class),
            'layout' => $layout,
            'canEditLayout' => $request->user()->can(PermissionEnum::SettingsManage->value) && $request->user()->can(PermissionEnum::ReportsView->value),
            'filters' => [
                'search' => $request->string('filter.search')->toString(),
                'status' => $request->string('filter.status')->toString(),
                'tag' => $request->string('filter.tag')->toString(),
                'source' => $request->string('filter.source')->toString(),
                'returning' => $request->string('filter.returning')->toString(),
                'contact' => $request->string('filter.contact')->toString(),
            ],
            'sort' => $sort,
            'statuses' => [
                ['value' => GuestDirectory::STATUS_VIP, 'label' => __('VIP')],
                ['value' => GuestDirectory::STATUS_BLACKLISTED, 'label' => __('Blacklisted')],
                ['value' => GuestDirectory::STATUS_ANONYMIZED, 'label' => __('Archived')],
            ],
            'tags' => $this->tags($property),
            'sources' => $property->bookingSources()->ordered()->get()
                ->map(fn (BookingSource $source): array => ['value' => (string) $source->id, 'label' => $source->name])
                ->values()
                ->all(),
            'returningOptions' => [
                ['value' => GuestDirectory::RETURNING_FIRST, 'label' => __('First stay')],
                ['value' => GuestDirectory::RETURNING_YES, 'label' => __('Returning')],
            ],
            'contactOptions' => [
                ['value' => GuestDirectory::CONTACT_MISSING, 'label' => __('Missing email or phone')],
                ['value' => GuestDirectory::CONTACT_NO_EMAIL, 'label' => __('No email')],
                ['value' => GuestDirectory::CONTACT_NO_PHONE, 'label' => __('No phone')],
            ],
            'currency' => CurrencyData::forProperty($property->loadMissing('settings')),
            'countries' => $canManage ? app(CountryOptions::class)->options() : [],
            'canManage' => $canManage,
            'maxBulkGuests' => BulkUpdateGuestsRequest::MAX_GUESTS,
            'bulkActions' => array_values(array_map(
                fn (GuestBulkAction $action): array => ['value' => $action->value, 'label' => $action->label()],
                array_filter(GuestBulkAction::cases(), fn (GuestBulkAction $action): bool => $request->user()->can($action->permission()->value)),
            )),
        ]);
    }

    /**
     * The first guests matching `?q=`, for the guest picker. Two characters are needed before it looks.
     */
    public function search(Request $request, Property $property, GuestDirectory $guestDirectory): JsonResponse
    {
        $guests = $guestDirectory->lookup($property, $request->string('q')->toString());

        return response()->json(['guests' => $guests->map(GuestOptionData::fromModel(...))->all()]);
    }

    public function store(GuestRequest $request, Property $property, CreateGuest $createGuest): RedirectResponse
    {
        $guest = $createGuest($request->user(), $property, $request->profileAttributes(), $request->tagNames(), $request->identityData(), $request->identityPhoto());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added.', ['name' => $guest->full_name])]);

        return to_route('guests.show', ['property' => $property, 'guest' => $guest]);
    }

    /**
     * Guest page, Details tab.
     */
    public function show(Request $request, Property $property, Guest $guest, GuestMatcher $guestMatcher): Response
    {
        $withIdNumbers = $request->user()->can(PermissionEnum::GuestsViewDocuments->value);

        $canEdit = $request->user()->can(PermissionEnum::GuestsManage->value) && $guest->anonymized_at === null;

        return $this->renderTab($request->user(), $guest, 'details', [
            'formOptions' => $canEdit ? $this->formOptions($property) : null,
            ...($withIdNumbers && $guest->anonymized_at === null ? $this->documentProps($request->user(), $property, $guest) : []),
            'duplicates' => array_map(
                fn (array $match): GuestMatchData => GuestMatchData::fromMatch($match),
                $guestMatcher->forGuest($guest->load('documents'), $withIdNumbers),
            ),
        ]);
    }

    /**
     * Guest page, Notes tab. Notes load only here, newest first.
     */
    public function notes(Request $request, Property $property, Guest $guest): Response
    {
        return $this->renderTab($request->user(), $guest, 'notes', [
            'notes' => $guest->notes()->with('author')->latest()->latest('id')->get()
                ->map(fn (GuestNote $note): GuestNoteData => GuestNoteData::fromModel($note))
                ->all(),
        ]);
    }

    /**
     * Guest page, Stays tab: every stay, newest arrival first. A guest's stays are few, so the tab is not paginated.
     */
    public function stays(Request $request, Property $property, Guest $guest): Response
    {
        return $this->renderTab($request->user(), $guest, 'stays', withStays: true);
    }

    /**
     * Guest page, Documents tab. Documents load only here, with masked numbers. The sheet form gets the full number
     * from the logged `reveal` request, never from page props.
     */
    public function documents(Request $request, Property $property, Guest $guest): Response
    {
        return $this->renderTab($request->user(), $guest, 'documents', $this->documentProps($request->user(), $property, $guest));
    }

    /**
     * @return array<string, mixed>
     */
    private function documentProps(User $user, Property $property, Guest $guest): array
    {
        $today = app(PropertyToday::class)->date($property)->toDateString();
        $canManage = $user->can(PermissionEnum::GuestsManage->value) && $guest->anonymized_at === null;

        return [
            'documentFormOptions' => $canManage ? [
                'types' => IdentityDocumentType::options(),
                'countries' => app(CountryOptions::class)->options(),
            ] : null,
            'retentionDays' => $property->settings?->id_document_retention_days,
            'documents' => $guest->documents()->with('media')->orderBy('id')->get()
                ->map(fn (GuestIdentityDocument $document): GuestIdentityDocumentData => GuestIdentityDocumentData::fromModel($document, $today))
                ->all(),
        ];
    }

    public function update(GuestRequest $request, Property $property, Guest $guest, UpdateGuest $updateGuest): RedirectResponse
    {
        $saved = $updateGuest($request->user(), $guest, $request->profileAttributes(), $request->tagNames(), $request->identityData(), $request->identityPhoto(), $request->identityDocumentId());

        $guest->refresh();

        Inertia::flash('toast', $saved
            ? ['type' => 'success', 'message' => __(':name saved.', ['name' => $guest->full_name])]
            : ['type' => 'error', 'message' => __(':name cannot be edited.', ['name' => $guest->full_name])]);

        return to_route('guests.show', ['property' => $property, 'guest' => $guest]);
    }

    public function markVip(Request $request, Property $property, Guest $guest, SetVip $setVip): RedirectResponse
    {
        Inertia::flash('toast', $setVip($request->user(), $guest, true)
            ? ['type' => 'success', 'message' => __(':name is now a VIP.', ['name' => $guest->full_name])]
            : ['type' => 'error', 'message' => __(':name is already a VIP.', ['name' => $guest->full_name])]);

        return back(fallback: route('guests.show', ['property' => $property, 'guest' => $guest]));
    }

    public function unmarkVip(Request $request, Property $property, Guest $guest, SetVip $setVip): RedirectResponse
    {
        Inertia::flash('toast', $setVip($request->user(), $guest, false)
            ? ['type' => 'success', 'message' => __(':name is no longer a VIP.', ['name' => $guest->full_name])]
            : ['type' => 'error', 'message' => __(':name is not a VIP.', ['name' => $guest->full_name])]);

        return back(fallback: route('guests.show', ['property' => $property, 'guest' => $guest]));
    }

    public function blacklist(BlacklistGuestRequest $request, Property $property, Guest $guest, BlacklistGuest $blacklistGuest): RedirectResponse
    {
        Inertia::flash('toast', $blacklistGuest($request->user(), $guest, $request->validated('reason'))
            ? ['type' => 'success', 'message' => __(':name blacklisted.', ['name' => $guest->full_name])]
            : ['type' => 'error', 'message' => __(':name is already blacklisted.', ['name' => $guest->full_name])]);

        return back(fallback: route('guests.show', ['property' => $property, 'guest' => $guest]));
    }

    public function unblacklist(Request $request, Property $property, Guest $guest, UnblacklistGuest $unblacklistGuest): RedirectResponse
    {
        Inertia::flash('toast', $unblacklistGuest($request->user(), $guest)
            ? ['type' => 'success', 'message' => __(':name removed from the blacklist.', ['name' => $guest->full_name])]
            : ['type' => 'error', 'message' => __(':name is not blacklisted.', ['name' => $guest->full_name])]);

        return back(fallback: route('guests.show', ['property' => $property, 'guest' => $guest]));
    }

    /**
     * Each tab has its own URL, so the open tab survives a refresh. Only the open tab's data is sent.
     *
     * The stay figures in the header are on every tab. `$withStays` also sends the stay rows.
     *
     * @param  'details'|'notes'|'stays'|'documents'  $tab
     * @param  array<string, mixed>  $tabProps
     */
    private function renderTab(User $user, Guest $guest, string $tab, array $tabProps = [], bool $withStays = false): Response
    {
        $guest->load(['tags', 'property.settings']);
        $stays = app(GuestStayHistory::class)->stays($guest);
        $since = app(PropertyToday::class)->date($guest->property)->subYear();
        if ($user->can(PermissionEnum::GuestsViewDocuments->value)) {
            $guest->loadMissing('documents.media');
        }

        return Inertia::render('guests/show', [
            'guest' => GuestData::fromModel($guest, $user->can(PermissionEnum::GuestsViewDocuments->value)),
            'tab' => $tab,
            'canManage' => $user->can(PermissionEnum::GuestsManage->value),
            'canBlacklist' => $user->can(PermissionEnum::GuestsBlacklist->value),
            'canViewDocuments' => $user->can(PermissionEnum::GuestsViewDocuments->value),
            'canMerge' => $user->can(PermissionEnum::GuestsMerge->value),
            'canAnonymize' => $user->can(PermissionEnum::GuestsAnonymize->value),
            'staySummary' => GuestStaySummaryData::fromStays($stays, $since),
            'currency' => CurrencyData::forProperty($guest->property),
            ...($withStays ? ['stays' => $stays->map(fn (ReservationUnit $unit): GuestStayData => GuestStayData::fromUnit($unit))->values()->all()] : []),
            ...$tabProps,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(Property $property): array
    {
        return [
            'countries' => app(CountryOptions::class)->options(),
            'genders' => Gender::options(),
            'locales' => Locale::options(),
            'tags' => $this->tags($property),
        ];
    }

    /**
     * @return list<TagData>
     */
    private function tags(Property $property): array
    {
        return array_values($property->tags()->orderByRaw('lower(name)')->get()
            ->map(fn (Tag $tag): TagData => TagData::fromModel($tag))
            ->all());
    }
}

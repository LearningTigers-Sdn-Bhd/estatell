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
use App\Data\Guests\TagData;
use App\Enums\Gender;
use App\Enums\GuestBulkAction;
use App\Enums\IdentityDocumentType;
use App\Enums\Locale;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Guests\BlacklistGuestRequest;
use App\Http\Requests\Guests\BulkUpdateGuestsRequest;
use App\Http\Requests\Guests\GuestRequest;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\GuestNote;
use App\Models\Property;
use App\Models\Tag;
use App\Models\User;
use App\Services\Guests\CountryOptions;
use App\Services\Guests\GuestDirectory;
use App\Services\Guests\GuestMatcher;
use App\Services\Property\PropertyToday;
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
    public function index(Request $request, Property $property, GuestDirectory $guestDirectory): Response
    {
        $canManage = $request->user()->can(PermissionEnum::GuestsManage->value);

        return Inertia::render('guests/index', [
            'guests' => GuestRowData::collect($guestDirectory->paginate($property, $request, $request->user()->can(PermissionEnum::GuestsViewDocuments->value)), PaginatedDataCollection::class),
            'filters' => [
                'search' => $request->string('filter.search')->toString(),
                'status' => $request->string('filter.status')->toString(),
                'tag' => $request->string('filter.tag')->toString(),
            ],
            'sort' => $request->string('sort', 'full_name')->toString(),
            'statuses' => [
                ['value' => GuestDirectory::STATUS_VIP, 'label' => __('VIP')],
                ['value' => GuestDirectory::STATUS_BLACKLISTED, 'label' => __('Blacklisted')],
                ['value' => GuestDirectory::STATUS_ANONYMIZED, 'label' => __('Archived')],
            ],
            'tags' => $this->tags($property),
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
        $guest = $createGuest($request->user(), $property, $request->profileAttributes(), $request->tagNames());

        if ($request->filled('identity_number')) {
            $type = $request->string('identity_type')->value() ?: 'mykad';
            $doc = $guest->documents()->firstOrNew(['type' => $type]);
            $doc->number = $request->string('identity_number')->value();
            $doc->issuing_country_code = $request->string('identity_country_code')->value() ?: 'MY';
            $doc->save();

            if ($request->filled('identity_photo')) {
                $doc->clearMediaCollection(GuestIdentityDocument::FRONT_SCAN);
                $doc->addMediaFromBase64($request->string('identity_photo')->value())
                    ->usingFileName('mykad_portrait.jpg')
                    ->toMediaCollection(GuestIdentityDocument::FRONT_SCAN);
            }
        }

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
     * Guest page, Documents tab. Documents load only here, with masked numbers. The sheet form gets the full number
     * from the logged `reveal` request, never from page props.
     */
    public function documents(Request $request, Property $property, Guest $guest, PropertyToday $propertyToday): Response
    {
        $today = $propertyToday->date($property)->toDateString();
        $canManage = $request->user()->can(PermissionEnum::GuestsManage->value);

        return $this->renderTab($request->user(), $guest, 'documents', [
            'documentFormOptions' => $canManage ? [
                'types' => IdentityDocumentType::options(),
                'countries' => app(CountryOptions::class)->options(),
            ] : null,
            'retentionDays' => $property->settings?->id_document_retention_days,
            'documents' => $guest->documents()->with('media')->orderBy('id')->get()
                ->map(fn (GuestIdentityDocument $document): GuestIdentityDocumentData => GuestIdentityDocumentData::fromModel($document, $today))
                ->all(),
        ]);
    }

    public function update(GuestRequest $request, Property $property, Guest $guest, UpdateGuest $updateGuest): RedirectResponse
    {
        $saved = $updateGuest($request->user(), $guest, $request->profileAttributes(), $request->tagNames());

        if ($request->filled('identity_number')) {
            $type = $request->string('identity_type')->value() ?: 'mykad';
            $doc = $guest->documents()->firstOrNew(['type' => $type]);
            $doc->number = $request->string('identity_number')->value();
            $doc->issuing_country_code = $request->string('identity_country_code')->value() ?: 'MY';
            $doc->save();

            if ($request->filled('identity_photo')) {
                $doc->clearMediaCollection(GuestIdentityDocument::FRONT_SCAN);
                $doc->addMediaFromBase64($request->string('identity_photo')->value())
                    ->usingFileName('mykad_portrait.jpg')
                    ->toMediaCollection(GuestIdentityDocument::FRONT_SCAN);
            }
        }

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
     * @param  'details'|'notes'|'documents'  $tab
     * @param  array<string, mixed>  $tabProps
     */
    private function renderTab(User $user, Guest $guest, string $tab, array $tabProps = []): Response
    {
        $guest->load(['tags', 'property', 'documents.media']);

        return Inertia::render('guests/show', [
            'guest' => GuestData::fromModel($guest),
            'tab' => $tab,
            'canManage' => $user->can(PermissionEnum::GuestsManage->value),
            'canBlacklist' => $user->can(PermissionEnum::GuestsBlacklist->value),
            'canViewDocuments' => $user->can(PermissionEnum::GuestsViewDocuments->value),
            'canMerge' => $user->can(PermissionEnum::GuestsMerge->value),
            'canAnonymize' => $user->can(PermissionEnum::GuestsAnonymize->value),
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

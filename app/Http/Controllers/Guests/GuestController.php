<?php

namespace App\Http\Controllers\Guests;

use App\Actions\Guests\BlacklistGuest;
use App\Actions\Guests\CreateGuest;
use App\Actions\Guests\SetVip;
use App\Actions\Guests\UnblacklistGuest;
use App\Actions\Guests\UpdateGuest;
use App\Data\Guests\GuestData;
use App\Data\Guests\GuestIdentityDocumentData;
use App\Data\Guests\GuestNoteData;
use App\Data\Guests\GuestRowData;
use App\Data\Guests\TagData;
use App\Enums\Gender;
use App\Enums\Locale;
use App\Enums\PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Guests\BlacklistGuestRequest;
use App\Http\Requests\Guests\GuestRequest;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\GuestNote;
use App\Models\Property;
use App\Models\Tag;
use App\Models\User;
use App\Services\Guests\CountryOptions;
use App\Services\Guests\GuestDirectory;
use App\Services\Property\PropertyToday;
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
            ],
            'tags' => $this->tags($property),
            'canManage' => $request->user()->can(PermissionEnum::GuestsManage->value),
        ]);
    }

    public function create(Property $property): Response
    {
        return Inertia::render('guests/form', [
            'guest' => null,
            ...$this->formOptions($property),
        ]);
    }

    public function store(GuestRequest $request, Property $property, CreateGuest $createGuest): RedirectResponse
    {
        $guest = $createGuest($request->user(), $property, $request->profileAttributes(), $request->tagNames());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added.', ['name' => $guest->full_name])]);

        return to_route('guests.show', ['property' => $property, 'guest' => $guest]);
    }

    /**
     * Guest page, Details tab.
     */
    public function show(Request $request, Property $property, Guest $guest): Response
    {
        return $this->renderTab($request->user(), $guest, 'details');
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
     * Guest page, Documents tab. Documents load only here, with masked numbers.
     */
    public function documents(Request $request, Property $property, Guest $guest, PropertyToday $propertyToday): Response
    {
        $today = $propertyToday->date($property)->toDateString();

        return $this->renderTab($request->user(), $guest, 'documents', [
            'documents' => $guest->documents()->with('media')->orderBy('id')->get()
                ->map(fn (GuestIdentityDocument $document): GuestIdentityDocumentData => GuestIdentityDocumentData::fromModel($document, $today))
                ->all(),
        ]);
    }

    public function edit(Property $property, Guest $guest): Response
    {
        $guest->load('tags');

        return Inertia::render('guests/form', [
            'guest' => GuestData::fromModel($guest),
            ...$this->formOptions($property),
        ]);
    }

    public function update(GuestRequest $request, Property $property, Guest $guest, UpdateGuest $updateGuest): RedirectResponse
    {
        $saved = $updateGuest($request->user(), $guest, $request->profileAttributes(), $request->tagNames());
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

        return to_route('guests.show', ['property' => $property, 'guest' => $guest]);
    }

    public function unmarkVip(Request $request, Property $property, Guest $guest, SetVip $setVip): RedirectResponse
    {
        Inertia::flash('toast', $setVip($request->user(), $guest, false)
            ? ['type' => 'success', 'message' => __(':name is no longer a VIP.', ['name' => $guest->full_name])]
            : ['type' => 'error', 'message' => __(':name is not a VIP.', ['name' => $guest->full_name])]);

        return to_route('guests.show', ['property' => $property, 'guest' => $guest]);
    }

    public function blacklist(BlacklistGuestRequest $request, Property $property, Guest $guest, BlacklistGuest $blacklistGuest): RedirectResponse
    {
        Inertia::flash('toast', $blacklistGuest($request->user(), $guest, $request->validated('reason'))
            ? ['type' => 'success', 'message' => __(':name blacklisted.', ['name' => $guest->full_name])]
            : ['type' => 'error', 'message' => __(':name is already blacklisted.', ['name' => $guest->full_name])]);

        return to_route('guests.show', ['property' => $property, 'guest' => $guest]);
    }

    public function unblacklist(Request $request, Property $property, Guest $guest, UnblacklistGuest $unblacklistGuest): RedirectResponse
    {
        Inertia::flash('toast', $unblacklistGuest($request->user(), $guest)
            ? ['type' => 'success', 'message' => __(':name removed from the blacklist.', ['name' => $guest->full_name])]
            : ['type' => 'error', 'message' => __(':name is not blacklisted.', ['name' => $guest->full_name])]);

        return to_route('guests.show', ['property' => $property, 'guest' => $guest]);
    }

    /**
     * Each tab has its own URL, so the open tab survives a refresh. Only the open tab's data is sent.
     *
     * @param  'details'|'notes'|'documents'  $tab
     * @param  array<string, mixed>  $tabProps
     */
    private function renderTab(User $user, Guest $guest, string $tab, array $tabProps = []): Response
    {
        $guest->load('tags');

        return Inertia::render('guests/show', [
            'guest' => GuestData::fromModel($guest),
            'tab' => $tab,
            'canManage' => $user->can(PermissionEnum::GuestsManage->value),
            'canBlacklist' => $user->can(PermissionEnum::GuestsBlacklist->value),
            'canViewDocuments' => $user->can(PermissionEnum::GuestsViewDocuments->value),
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
            'defaultCountryCode' => $property->country_code,
        ];
    }

    /**
     * @return list<TagData>
     */
    private function tags(Property $property): array
    {
        return $property->tags()->orderByRaw('lower(name)')->get()
            ->map(fn (Tag $tag): TagData => TagData::fromModel($tag))
            ->all();
    }
}

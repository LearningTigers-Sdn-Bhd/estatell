<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Platform\CreatePartnerWithTemporaryPassword;
use App\Data\Platform\AdminPartnerRowData;
use App\Enums\PartnerStatus;
use App\Enums\TemporaryPasswordExportFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AddPartnerRequest;
use App\Services\Platform\PartnerDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

class PartnerController extends Controller
{
    /**
     * List the Active partners. The Invited tab comes with its own page.
     */
    public function index(Request $request, PartnerDirectory $partnerDirectory): Response
    {
        return Inertia::render('admin/partners/index', [
            'tab' => 'active',
            'exportFormats' => TemporaryPasswordExportFormat::options(),
            'partners' => AdminPartnerRowData::collect($partnerDirectory->paginate(PartnerStatus::Active), PaginatedDataCollection::class),
        ]);
    }

    /**
     * List the Suspended partners.
     */
    public function suspended(PartnerDirectory $partnerDirectory): Response
    {
        return Inertia::render('admin/partners/index', [
            'tab' => 'suspended',
            'partners' => AdminPartnerRowData::collect($partnerDirectory->paginate(PartnerStatus::Suspended), PaginatedDataCollection::class),
        ]);
    }

    /**
     * Add a partner and its login with a temp password. The new user's id is flashed, so the page can open the
     * copy message for it; the temp password is never a page prop or in the session.
     */
    public function store(AddPartnerRequest $request, CreatePartnerWithTemporaryPassword $createPartner): RedirectResponse
    {
        $user = $createPartner($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was added.', ['name' => $user->partner->name])]);
        Inertia::flash('partnerAddedId', $user->id);

        return to_route('admin.partners.index');
    }
}

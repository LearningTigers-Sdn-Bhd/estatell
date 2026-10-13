<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Platform\CreatePartnerWithTemporaryPassword;
use App\Data\Platform\AdminPartnerRowData;
use App\Data\Staff\TemporaryPasswordSlipData;
use App\Enums\PartnerStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AddPartnerRequest;
use App\Services\Platform\PartnerDirectory;
use App\Services\Staff\TemporaryPasswordMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

class PartnerController extends Controller
{
    /**
     * List the Active partners. The Invited and Suspended tabs come with their own pages.
     */
    public function index(Request $request, PartnerDirectory $partnerDirectory): Response
    {
        return Inertia::render('admin/partners/index', [
            'tab' => 'active',
            'partners' => AdminPartnerRowData::collect($partnerDirectory->paginate(PartnerStatus::Active), PaginatedDataCollection::class),
        ]);
    }

    /**
     * Add a partner and its login with a temp password. The sign-in message is flashed once, so the page can show
     * it in a Dialog; the temp password is never a page prop.
     */
    public function store(AddPartnerRequest $request, CreatePartnerWithTemporaryPassword $createPartner, TemporaryPasswordMessage $message): RedirectResponse
    {
        $user = $createPartner($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was added.', ['name' => $user->partner->name])]);
        Inertia::flash('partnerSignInMessage', $message->for(collect([TemporaryPasswordSlipData::fromPartner($user)])));

        return to_route('admin.partners.index');
    }
}

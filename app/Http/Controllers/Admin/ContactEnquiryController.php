<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Platform\DeleteContactEnquiry;
use App\Actions\Platform\UpdateContactEnquiryStatus;
use App\Data\Platform\AdminContactEnquiryRowData;
use App\Enums\ContactEnquiryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateContactEnquiryStatusRequest;
use App\Models\ContactEnquiry;
use App\Services\Platform\ContactEnquiryDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

class ContactEnquiryController extends Controller
{
    /**
     * List the enquiries with search, a status filter and sorting in the URL.
     */
    public function index(Request $request, ContactEnquiryDirectory $enquiries): Response
    {
        return Inertia::render('admin/enquiries/index', [
            'enquiries' => AdminContactEnquiryRowData::collect($enquiries->paginate($request), PaginatedDataCollection::class),
            'counts' => $enquiries->statusCounts(),
            'filters' => [
                'search' => $request->string('filter.search')->toString(),
                'status' => $request->string('filter.status')->toString(),
            ],
            'sort' => $request->string('sort', '-created_at')->toString(),
            'statuses' => ContactEnquiryStatus::options(),
        ]);
    }

    /**
     * Show one enquiry.
     */
    public function show(ContactEnquiry $enquiry): Response
    {
        return Inertia::render('admin/enquiries/show', [
            'enquiry' => AdminContactEnquiryRowData::from($enquiry),
            'statuses' => ContactEnquiryStatus::options(),
        ]);
    }

    public function destroy(Request $request, ContactEnquiry $enquiry, DeleteContactEnquiry $deleteEnquiry): RedirectResponse
    {
        $deleteEnquiry($request->user(), $enquiry);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Enquiry deleted.')]);

        return to_route('admin.enquiries.index');
    }

    /**
     * Mark an enquiry New, Contacted or Closed.
     */
    public function updateStatus(UpdateContactEnquiryStatusRequest $request, ContactEnquiry $enquiry, UpdateContactEnquiryStatus $updateStatus): RedirectResponse
    {
        $status = ContactEnquiryStatus::from($request->string('status')->toString());

        Inertia::flash('toast', $updateStatus($request->user(), $enquiry, $status)
            ? ['type' => 'success', 'message' => __('Enquiry marked :status.', ['status' => mb_strtolower($status->label())])]
            : ['type' => 'error', 'message' => __('This enquiry is already :status.', ['status' => mb_strtolower($status->label())])]);

        return back(fallback: route('admin.enquiries.index'));
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Platform\BulkCreatePartners;
use App\Enums\SpreadsheetFormat;
use App\Enums\TemporaryPasswordExportFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkAddPartnersRequest;
use App\Http\Requests\Admin\ImportPartnersRequest;
use App\Models\User;
use App\Services\Platform\PartnerImportReader;
use App\Services\Platform\PartnerImportTemplate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PartnerBulkAddController extends Controller
{
    /**
     * Bulk add page: a grid of rows, filled by hand or from an uploaded file.
     */
    public function create(): Response
    {
        return Inertia::render('admin/partners/bulk', [
            'maxRows' => PartnerImportReader::MAX_ROWS,
            'exportFormats' => TemporaryPasswordExportFormat::options(),
        ]);
    }

    /**
     * Create every row, then show the created partners on the same page (flash `bulkAdded`). The temp passwords
     * are never flashed. If another admin takes a prefix or email while saving, nothing is saved.
     */
    public function store(BulkAddPartnersRequest $request, BulkCreatePartners $bulkCreatePartners): RedirectResponse
    {
        try {
            $users = $bulkCreatePartners($request->user(), $request->rows());
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors([
                'rows' => __('A prefix or email was taken while you saved. Nothing was saved.'),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count partner added.|:count partners added.', $users->count())]);
        Inertia::flash('bulkAdded', $users->map(fn (User $user): array => [
            'id' => $user->id,
            'partner' => $user->partner->name,
            'name' => $user->name,
            'email' => $user->email,
        ])->all());

        return to_route('admin.partners.bulk.create');
    }

    /**
     * Read an uploaded CSV or XLSX file into grid rows. Nothing is saved.
     */
    public function import(ImportPartnersRequest $request, PartnerImportReader $reader): JsonResponse
    {
        return response()->json([
            'rows' => $reader->read($request->file('file')),
        ]);
    }

    /**
     * The empty file with the partner name, prefix, user name and email columns.
     */
    public function template(SpreadsheetFormat $format, PartnerImportTemplate $template): BinaryFileResponse
    {
        return response()
            ->download($template->write($format), 'partners-import.'.$format->value)
            ->deleteFileAfterSend();
    }
}

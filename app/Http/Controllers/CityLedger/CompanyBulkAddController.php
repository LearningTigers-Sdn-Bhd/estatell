<?php

namespace App\Http\Controllers\CityLedger;

use App\Actions\CityLedger\BulkCreateCompanies;
use App\Enums\CompanyImportMode;
use App\Enums\CompanyType;
use App\Enums\SpreadsheetFormat;
use App\Enums\TemporaryPasswordExportFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\CityLedger\BulkAddCompaniesRequest;
use App\Http\Requests\CityLedger\ImportCompaniesRequest;
use App\Models\CompanyUser;
use App\Models\Property;
use App\Services\CityLedger\CompanyImportReader;
use App\Services\CityLedger\CompanyImportTemplate;
use App\Services\Guests\CountryOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CompanyBulkAddController extends Controller
{
    /**
     * Bulk add page: a grid of rows in Simple or Detailed mode, filled by hand or from an uploaded file.
     */
    public function create(): Response
    {
        return Inertia::render('finance/city-ledger/companies/bulk', [
            'types' => CompanyType::options(),
            'countries' => app(CountryOptions::class)->options(),
            'modes' => CompanyImportMode::options(),
            'maxRows' => CompanyImportReader::MAX_ROWS,
            'exportFormats' => TemporaryPasswordExportFormat::options(),
        ]);
    }

    /**
     * Create every row. Back to the list, or, when some rows got a temp password login, back to this page to share them (flash `bulkAdded`).
     */
    public function store(BulkAddCompaniesRequest $request, Property $property, BulkCreateCompanies $bulkCreateCompanies): RedirectResponse
    {
        $companies = $bulkCreateCompanies($request->user(), $property, $request->rows());

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count company added.|:count companies added.', $companies->count())]);

        $logins = CompanyUser::query()->active()
            ->whereIn('company_id', $companies->pluck('id')->all())
            ->whereHas('user', fn ($query) => $query->whereNotNull('temporary_password'))
            ->with('company', 'user')
            ->get();

        if ($logins->isEmpty()) {
            return to_route('finance.city-ledger.companies.index', $property);
        }

        Inertia::flash('bulkAdded', $logins->map(fn (CompanyUser $link): array => ['id' => $link->user_id, 'name' => $link->company->name, 'email' => $link->user->email])->values()->all());

        return to_route('finance.city-ledger.companies.bulk.create', $property);
    }

    /**
     * Read an uploaded CSV or XLSX file into grid rows. Nothing is saved.
     */
    public function import(ImportCompaniesRequest $request, Property $property, CompanyImportReader $reader): JsonResponse
    {
        return response()->json($reader->read($request->file('file')));
    }

    /**
     * The empty file with the columns of the chosen mode (`?mode=simple|detailed`, Simple by default).
     */
    public function template(Request $request, Property $property, SpreadsheetFormat $format, CompanyImportTemplate $template): BinaryFileResponse
    {
        $mode = CompanyImportMode::tryFrom($request->string('mode')->toString()) ?? CompanyImportMode::Simple;

        return response()
            ->download($template->write($format, $mode), "companies-{$mode->value}.{$format->value}")
            ->deleteFileAfterSend();
    }
}

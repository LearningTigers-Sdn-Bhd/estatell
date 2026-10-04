<?php

namespace App\Http\Controllers\Staff;

use App\Actions\Staff\BulkCreateStaff;
use App\Data\Staff\RoleOptionData;
use App\Enums\SpreadsheetFormat;
use App\Enums\TemporaryPasswordExportFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\BulkAddStaffRequest;
use App\Http\Requests\Staff\ImportStaffRequest;
use App\Models\Property;
use App\Models\User;
use App\Services\Staff\AssignableRoles;
use App\Services\Staff\StaffImportReader;
use App\Services\Staff\StaffImportTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StaffBulkAddController extends Controller
{
    /**
     * Bulk add page: a grid of rows, filled by hand or from an uploaded file.
     */
    public function create(Request $request, Property $property, AssignableRoles $assignableRoles): Response
    {
        return Inertia::render('settings/staff/bulk', [
            'roles' => $assignableRoles->for($property, $request->user())->map(fn ($role): RoleOptionData => RoleOptionData::fromModel($role)),
            'maxRows' => StaffImportReader::MAX_ROWS,
            'exportFormats' => TemporaryPasswordExportFormat::options(),
        ]);
    }

    /**
     * Create every row, then show the created staff on the same page (flash `bulkAdded`).
     */
    public function store(BulkAddStaffRequest $request, Property $property, BulkCreateStaff $bulkCreateStaff): RedirectResponse
    {
        $users = $bulkCreateStaff($property, $request->user(), $request->rows());

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count staff added.|:count staff added.', $users->count())]);
        Inertia::flash('bulkAdded', $users->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])->all());

        return to_route('settings.staff.bulk.create', $property);
    }

    /**
     * Read an uploaded CSV or XLSX file into grid rows. Nothing is saved.
     */
    public function import(ImportStaffRequest $request, Property $property, AssignableRoles $assignableRoles, StaffImportReader $reader): JsonResponse
    {
        return response()->json([
            'rows' => $reader->read($request->file('file'), $assignableRoles->for($property, $request->user())),
        ]);
    }

    /**
     * The empty file with the name, email and role columns.
     */
    public function template(Property $property, SpreadsheetFormat $format, StaffImportTemplate $template): BinaryFileResponse
    {
        return response()
            ->download($template->write($format), 'staff-import.'.$format->value)
            ->deleteFileAfterSend();
    }
}

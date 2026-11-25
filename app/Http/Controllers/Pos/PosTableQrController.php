<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\IssueTableQrSession;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\Concerns\RespondsToPos;
use App\Models\PosTable;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff print a table's dynamic QR from the register. The page shows the link to print or send to the table.
 */
class PosTableQrController extends Controller
{
    use RespondsToPos;

    public function issue(Request $request, Property $property, int $table, IssueTableQrSession $issueTableQrSession): Response|RedirectResponse
    {
        $model = PosTable::query()->where('property_id', $property->id)->findOrFail($table);
        $result = $issueTableQrSession($this->actor($request), $property, $model);

        if ($result->isRefused()) {
            $this->toast($result->refusal, '');

            return back();
        }

        return Inertia::render('pos/table-qr', [
            'table' => $model->code,
            'url' => route('qr.table.show', ['token' => $result->model->token]),
            'expiresAt' => $result->model->expires_at?->toIso8601String(),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\IssueTableQrSession;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\Concerns\RespondsToPos;
use App\Models\PosQrSession;
use App\Models\PosTable;
use App\Models\Property;
use App\Services\Pos\PosReceiptClaimQr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff print a table's dynamic QR from the register. The page shows the scannable QR and link to print for the table.
 */
class PosTableQrController extends Controller
{
    use RespondsToPos;

    public function issue(Request $request, Property $property, int $table, IssueTableQrSession $issueTableQrSession, PosReceiptClaimQr $qr): Response|RedirectResponse
    {
        $model = PosTable::query()->where('property_id', $property->id)->findOrFail($table);
        $result = $issueTableQrSession($this->actor($request), $property, $model);

        if ($result->isRefused()) {
            $this->toast($result->refusal, '');

            return back();
        }

        $session = $result->model;
        abort_unless($session instanceof PosQrSession, 500);
        $url = route('qr.table.show', ['token' => $session->token]);

        return Inertia::render('pos/table-qr', [
            'table' => $model->code,
            'url' => $url,
            'qrSvg' => $qr->svg($url),
            'expiresAt' => $session->expires_at?->toIso8601String(),
        ]);
    }
}

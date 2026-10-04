<?php

namespace App\Http\Controllers\CityLedger;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Property;
use App\Services\CityLedger\ArInvoiceDirectory;
use App\Services\CityLedger\CompanyBillTo;
use App\Services\CityLedger\StatementBuilder;
use App\Services\Folio\Letterhead;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The statement of account of a company as an A4 PDF. The tab itself is `CompanyController::statement`. See
 * docs/15-city-ledger/04-credit-control-and-reports.md#statement-of-account.
 */
class CompanyStatementController extends Controller
{
    public function pdf(Request $request, Property $property, Company $company, StatementBuilder $builder, ArInvoiceDirectory $directory, Letterhead $letterhead, CompanyBillTo $billTo): Response
    {
        [$from, $to] = self::range($request, $directory->today($property));
        $statement = $builder->build($company, $from, $to);

        return Pdf::loadView('pdf.ar-statement', [
            'statement' => $statement,
            'company' => $company,
            'billToLines' => $billTo->lines($company),
            'letterhead' => $letterhead->for($property),
            'currency' => $property->settings?->currency->value ?? '',
        ])
            ->setPaper('a4', 'portrait')
            ->setOption('isFontSubsettingEnabled', true)
            ->stream('statement-'.str($company->name)->slug().'-'.$statement->to.'.pdf');
    }

    /**
     * The statement range from `from` and `to`: the first of the month to the business date by default, `to` never
     * after the business date and `from` never after `to`.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function range(Request $request, string $businessDate): array
    {
        $today = CarbonImmutable::parse($businessDate);
        $to = $request->date('to', 'Y-m-d')?->toImmutable()->startOfDay() ?? $today;
        $to = $to->gt($today) ? $today : $to;
        $from = $request->date('from', 'Y-m-d')?->toImmutable()->startOfDay() ?? $to->startOfMonth();

        return [$from->gt($to) ? $to : $from, $to];
    }
}

<?php

namespace App\Http\Controllers\Reservations;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Property\PrintedPdf;
use App\Services\Reservations\BookingSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BookingSummaryController extends Controller
{
    /**
     * The booking summary as an A4 PDF shown inline. It is rendered on demand and stores nothing. `?prices=0` prints it
     * without prices; a reservation with no company ignores that.
     */
    public function __invoke(Request $request, Property $property, Reservation $reservation, BookingSummary $bookingSummary, PrintedPdf $printedPdf): Response
    {
        $summary = $bookingSummary->for($property, $reservation, $request->boolean('prices', true));

        return $printedPdf->make($property, 'pdf.booking-summary', [
            'summary' => $summary,
            'settings' => $property->printSetting,
        ])->stream('booking-summary-'.$reservation->reference.'.pdf');
    }
}

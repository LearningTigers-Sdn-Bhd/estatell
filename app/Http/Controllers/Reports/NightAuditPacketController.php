<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\Reports\NightAuditPacket;
use Symfony\Component\HttpFoundation\Response;

class NightAuditPacketController extends Controller
{
    /**
     * The night audit packet PDF of a business date, shown in the browser. A date whose audit is not Completed, or that
     * is not Closed, is a 404.
     */
    public function __invoke(Property $property, string $date, NightAuditPacket $packet): Response
    {
        $audit = $packet->audit($property, $date);

        return $packet->make($audit)->stream($packet->filename($audit));
    }
}

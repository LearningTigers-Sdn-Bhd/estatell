<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;

/**
 * The company's statement at the property: invoices, payments and their PDFs.
 *
 * Placeholder: the routes exist so both portal items can be built at the same time. `feat(portal): show company statements and details` fills in the methods.
 */
class PortalStatementController extends Controller
{
    /**
     * The statements page.
     */
    public function show(): never
    {
        abort(404);
    }

    /**
     * The statement PDF.
     */
    public function pdf(): never
    {
        abort(404);
    }

    /**
     * One AR invoice PDF.
     */
    public function invoicePdf(): never
    {
        abort(404);
    }

    /**
     * One AR payment receipt.
     */
    public function receipt(): never
    {
        abort(404);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class EInvoiceGuideController extends Controller
{
    /**
     * Show the LHDN MyInvois e-invoice guidelines and official reference in superadmin.
     */
    public function __invoke(): Response
    {
        return Inertia::render('admin/e-invoice-guide');
    }
}

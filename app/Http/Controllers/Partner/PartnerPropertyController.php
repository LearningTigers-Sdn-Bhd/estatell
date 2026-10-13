<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class PartnerPropertyController extends Controller
{
    /**
     * The partner's properties. The list comes with the "let partners create a property" item; until then the page is empty.
     */
    public function index(): Response
    {
        return Inertia::render('partner/properties/index');
    }
}

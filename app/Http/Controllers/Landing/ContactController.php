<?php

namespace App\Http\Controllers\Landing;

use App\Actions\Platform\SubmitContactEnquiry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Landing\ContactEnquiryRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('contact', ['sent' => (bool) session()->pull('contact_sent', false)]);
    }

    public function store(ContactEnquiryRequest $request, SubmitContactEnquiry $submitContactEnquiry): RedirectResponse
    {
        $submitContactEnquiry($request->safe()->except('website'));

        return to_route('contact')->with('contact_sent', true);
    }
}

<?php

namespace App\Http\Requests\Concierge;

/**
 * A quote link guest's details. The room, plan and dates come from the quote, so only the details are sent.
 */
class BookQuoteRequest extends SaveIdentityRequest {}

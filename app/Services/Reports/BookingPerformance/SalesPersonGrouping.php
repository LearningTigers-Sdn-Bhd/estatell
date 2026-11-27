<?php

namespace App\Services\Reports\BookingPerformance;

use App\Models\User;

/**
 * By the staff member who sold the booking. See docs/12-reservations/12-sales-person.md.
 */
class SalesPersonGrouping extends NamedRecordGrouping
{
    protected function column(): string
    {
        return 'sales_person_id';
    }

    protected function model(): string
    {
        return User::class;
    }

    public function noneLabel(): string
    {
        return __('No sales person');
    }
}

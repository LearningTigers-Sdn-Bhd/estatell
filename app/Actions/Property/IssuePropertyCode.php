<?php

namespace App\Actions\Property;

use Illuminate\Support\Facades\DB;

class IssuePropertyCode
{
    /**
     * Issue the next property code for a prefix, for example ES10101.
     *
     * Call this inside the transaction that creates the property. The counter row is locked until
     * that transaction ends, so a rollback gives the number back and codes never skip or repeat.
     */
    public function __invoke(string $prefix = 'ES'): string
    {
        $row = DB::selectOne(
            'INSERT INTO property_code_counters (prefix, last_number) VALUES (?, 10101)
            ON CONFLICT (prefix) DO UPDATE SET last_number = property_code_counters.last_number + 1
            RETURNING last_number',
            [$prefix],
        );

        return $prefix.$row->last_number;
    }
}

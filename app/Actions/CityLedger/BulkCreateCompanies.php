<?php

namespace App\Actions\CityLedger;

use App\Models\Company;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BulkCreateCompanies
{
    public function __construct(private CreateCompany $createCompany) {}

    /**
     * Create every row as an Active company, all or nothing. The request has already checked every row.
     *
     * @param  list<array<string, mixed>>  $rows  Validated company fields per row.
     * @return Collection<int, Company>
     */
    public function __invoke(User $actor, Property $property, array $rows): Collection
    {
        return DB::transaction(fn (): Collection => collect($rows)->map(
            fn (array $row): Company => ($this->createCompany)($actor, $property, $row),
        ));
    }
}

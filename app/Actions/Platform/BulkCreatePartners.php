<?php

namespace App\Actions\Platform;

use App\Data\Platform\NewPartnerData;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BulkCreatePartners
{
    public function __construct(private CreatePartnerWithTemporaryPassword $createPartner) {}

    /**
     * Create every row as an Active partner with its login and a temp password, all or nothing. Each partner is
     * logged on its own; there is no batch log.
     *
     * The request has already checked every row (prefix free, email free, no repeats in the batch).
     *
     * @param  list<NewPartnerData>  $rows
     * @return Collection<int, User>
     */
    public function __invoke(User $admin, array $rows): Collection
    {
        return DB::transaction(fn (): Collection => collect($rows)->map(fn (NewPartnerData $row): User => ($this->createPartner)($admin, $row)));
    }
}

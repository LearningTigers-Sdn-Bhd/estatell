<?php

namespace App\Actions\Pos;

use App\Models\PosConnection;
use App\Models\PosGuestLink;
use App\Services\Pos\Loyverse\LoyverseClient;
use App\Services\Pos\Loyverse\LoyverseGuestLabel;

/**
 * Take a departed guest off the cashier's list: delete the Loyverse customer, or rename it "[Checked Out] ..." when
 * Loyverse refuses the delete, then drop the link row. A unit with no link has nothing to remove.
 */
class RemoveLoyverseGuest
{
    public function __construct(private LoyverseClient $client) {}

    public function __invoke(PosConnection $connection, int $reservationUnitId): void
    {
        $link = PosGuestLink::query()->where('pos_connection_id', $connection->id)->where('reservation_unit_id', $reservationUnitId)->first();

        if ($link === null) {
            return;
        }

        if (! $this->client->deleteCustomer($connection, $link->external_customer_id)) {
            $this->client->saveCustomer($connection, $link->external_customer_id, LoyverseGuestLabel::checkedOut($link->synced_label), 'Checked out');
        }

        $link->delete();
    }
}

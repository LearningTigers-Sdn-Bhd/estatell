<?php

namespace App\Http\Requests\Portal;

use App\Http\Requests\Reservations\BoatTripsRequest;

/**
 * The boats of a portal booking, the same shape as staff send. The portal route has the booking's id, not the model:
 * the controller finds it through the company, so another company's booking is a 404.
 */
class UpdatePortalBoatsRequest extends BoatTripsRequest
{
    protected function reservationId(): int
    {
        return (int) $this->route('reservation');
    }
}

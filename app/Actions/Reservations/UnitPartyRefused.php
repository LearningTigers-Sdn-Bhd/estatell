<?php

namespace App\Actions\Reservations;

use RuntimeException;

/**
 * Stops `ChangeUnitParty` when a step it calls refuses, so its transaction rolls back. The message is the refusal code.
 */
class UnitPartyRefused extends RuntimeException {}

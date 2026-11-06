<?php

namespace App\Services\BookingEngine;

use RuntimeException;

/**
 * A Google Maps link the owner pasted could not be read. The message is safe to show.
 */
class InvalidMapsLink extends RuntimeException {}

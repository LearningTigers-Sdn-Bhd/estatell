<?php

namespace App\Actions\Onboarding\Wastays;

use RuntimeException;

/**
 * The replay cannot go on with one day. The message names the wastays row and says why; nothing of the day is kept.
 */
class ReplayStopped extends RuntimeException {}

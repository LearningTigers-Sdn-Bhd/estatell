<?php

namespace App\Actions\Transfers;

use RuntimeException;

/** Rolls a van run back when one of its transfers is refused (`AssignTransferRun`). The message is the refusal. */
final class TransferRunRefused extends RuntimeException {}

<?php

namespace App\Services\EInvoice;

/**
 * LHDN refused or did not answer the hotel's MyInvois login. No document was sent, so a send that fails this way can
 * be tried again safely.
 */
class MyInvoisLoginFailed extends MyInvoisApiException {}

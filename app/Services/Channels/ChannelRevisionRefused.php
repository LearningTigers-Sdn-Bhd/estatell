<?php

namespace App\Services\Channels;

use RuntimeException;

/**
 * A booking revision that estatell cannot save as it stands. The message is shown to the admin on the Channels tab.
 */
class ChannelRevisionRefused extends RuntimeException {}

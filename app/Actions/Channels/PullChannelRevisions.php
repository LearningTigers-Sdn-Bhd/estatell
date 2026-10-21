<?php

namespace App\Actions\Channels;

use App\Features\ChannelManager;
use App\Models\ChannelProperty;
use App\Services\Channels\ChannelDriverManager;
use App\Services\Channels\Channex\ChannexRequestFailed;
use App\Services\Channels\Channex\ChannexRetryLater;
use Laravel\Pennant\Feature;

class PullChannelRevisions
{
    /** Seconds to wait after Channex refused the feed request, so a bad key is not retried in a loop. */
    private const FAILURE_RETRY_SECONDS = 300;

    public function __construct(
        private ChannelDriverManager $drivers,
        private StoreChannelRevision $storeChannelRevision,
        private IngestChannelBooking $ingestChannelBooking,
    ) {}

    /**
     * Read the revisions the provider has not seen acknowledged and save each one, oldest first. A property that is
     * not on the channel manager is skipped. A rate limit or provider error stops the pull and returns the seconds to
     * wait before the next try; the revisions stay unacknowledged at the provider, so none is lost. A refused revision
     * does not stop the others. Returns null when the pull ran through.
     */
    public function __invoke(ChannelProperty $channelProperty): ?int
    {
        $channelProperty->loadMissing('property');

        if (! Feature::for($channelProperty->property)->active(ChannelManager::class)) {
            return null;
        }

        try {
            $revisions = $this->drivers->forProvider($channelProperty->provider)->fetchRevisions($channelProperty->external_property_id);
        } catch (ChannexRetryLater $exception) {
            return $exception->retryAfter;
        } catch (ChannexRequestFailed) {
            return self::FAILURE_RETRY_SECONDS;
        }

        foreach ($revisions as $revision) {
            $stored = ($this->storeChannelRevision)($channelProperty, $revision);

            if ($stored !== null) {
                ($this->ingestChannelBooking)($stored);
            }
        }

        return null;
    }
}

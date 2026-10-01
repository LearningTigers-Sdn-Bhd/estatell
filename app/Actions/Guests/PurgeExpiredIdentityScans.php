<?php

namespace App\Actions\Guests;

use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\PropertySetting;
use Illuminate\Database\Eloquent\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class PurgeExpiredIdentityScans
{
    /**
     * Delete the front and back scan files that are older than each property's `id_document_retention_days`.
     * The age is counted from the day the file was uploaded. A property without a value keeps its scans.
     * The document and its encrypted number stay. One property failing does not stop the others.
     *
     * Returns how many scan files were deleted. Running it again finds nothing more to delete.
     */
    public function __invoke(): int
    {
        $deleted = 0;

        PropertySetting::query()->whereNotNull('id_document_retention_days')->orderBy('id')->each(function (PropertySetting $settings) use (&$deleted): void {
            try {
                $deleted += $this->purgeProperty($settings->property_id, $settings->id_document_retention_days);
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        return $deleted;
    }

    private function purgeProperty(int $propertyId, int $retentionDays): int
    {
        $documentIds = GuestIdentityDocument::query()
            ->whereIn('guest_id', Guest::query()->where('property_id', $propertyId)->select('id'))
            ->select('id');

        /** @var Collection<int, Media> $expired */
        $expired = Media::query()
            ->where('model_type', (new GuestIdentityDocument)->getMorphClass())
            ->whereIn('model_id', $documentIds)
            ->whereIn('collection_name', [GuestIdentityDocument::FRONT_SCAN, GuestIdentityDocument::BACK_SCAN])
            ->where('created_at', '<', now()->subDays($retentionDays))
            ->get();

        if ($expired->isEmpty()) {
            return 0;
        }

        $documents = GuestIdentityDocument::query()->whereIn('id', $expired->pluck('model_id')->unique())->get()->keyBy('id');

        foreach ($expired->groupBy(fn (Media $media): int => $documents[$media->model_id]->guest_id) as $guestId => $scans) {
            $scans->each(fn (Media $media) => $media->delete());

            activity()
                ->performedOn(Guest::query()->findOrFail($guestId))
                ->event('guest.document_scans_purged')
                ->withProperties([
                    'property_id' => $propertyId,
                    'document_ids' => $scans->pluck('model_id')->unique()->values()->all(),
                    'scans_deleted' => $scans->count(),
                    'retention_days' => $retentionDays,
                ])
                ->log('Expired ID scans deleted');
        }

        return $expired->count();
    }
}

<?php

namespace App\Actions\Pos;

use App\Models\PosMenuSnapshot;
use App\Models\PosOutlet;
use App\Models\Property;
use App\Services\Pos\OutletMenuSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Store the outlet's current menu snapshot for offline selling, and drop snapshots older than seven days. The same menu
 * is stored once, by its version. A refusal writes nothing. See docs/34-native-pos/06-kitchen-kds-and-qr.md.
 */
class IssuePosMenuSnapshot
{
    public const RETENTION_DAYS = 7;

    public function __construct(private OutletMenuSnapshot $menu) {}

    /**
     * @return array<string, mixed> The snapshot payload, with its `version`.
     */
    public function __invoke(Property $property, PosOutlet $outlet): array
    {
        return DB::transaction(function () use ($property, $outlet): array {
            $payload = ($this->menu)($outlet);

            PosMenuSnapshot::query()->firstOrCreate(
                ['pos_outlet_id' => $outlet->id, 'version' => $payload['version']],
                ['property_id' => $property->id, 'payload' => $payload, 'issued_at' => now()],
            );

            PosMenuSnapshot::query()
                ->where('property_id', $property->id)
                ->where('issued_at', '<', now()->subDays(self::RETENTION_DAYS))
                ->delete();

            return $payload;
        });
    }
}

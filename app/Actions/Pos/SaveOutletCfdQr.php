<?php

namespace App\Actions\Pos;

use App\Models\PosOutlet;
use App\Models\PosRegister;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Store or remove an outlet's static DuitNow QR image (PNG, JPG or WebP, up to 1 MB) on the public media disk, deleting the
 * file it replaces. Bumps the display version of every active register in the outlet, so a counter screen picks it up.
 * See docs/34-native-pos/10-customer-facing-display.md.
 */
class SaveOutletCfdQr
{
    public function __construct(private BumpCfdVersion $bumpCfdVersion) {}

    public function __invoke(User $actor, Property $property, PosOutlet $outlet, ?UploadedFile $file): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $outlet, $file): PosActionResult {
            $outlet = PosOutlet::query()->where('property_id', $property->id)->whereKey($outlet->id)->lockForUpdate()->first();

            if ($outlet === null) {
                return PosActionResult::refused('outlet_invalid');
            }

            $disk = Storage::disk(config('filesystems.public_media'));

            if ($outlet->cfd_duitnow_qr_path !== null) {
                $disk->delete($outlet->cfd_duitnow_qr_path);
            }

            $path = $file?->storePubliclyAs('pos/cfd-qr', $outlet->id.'.'.$file->extension(), config('filesystems.public_media'));

            $outlet->forceFill(['cfd_duitnow_qr_path' => $path])->save();

            $registerIds = PosRegister::query()->where('pos_outlet_id', $outlet->id)->where('active', true)->pluck('id');

            foreach ($registerIds as $registerId) {
                ($this->bumpCfdVersion)((int) $registerId);
            }

            activity()->performedOn($outlet)->causedBy($actor)->event('pos.outlet_cfd_qr_saved')
                ->withProperties(['property_id' => $property->id, 'path' => $path])
                ->log($file === null ? 'POS customer display DuitNow QR removed' : 'POS customer display DuitNow QR uploaded');

            return PosActionResult::saved($outlet);
        });
    }
}

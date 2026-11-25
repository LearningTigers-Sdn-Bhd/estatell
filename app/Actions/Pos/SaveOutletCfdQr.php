<?php

namespace App\Actions\Pos;

use App\Models\PosOutlet;
use App\Models\PosRegister;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Store or remove an outlet's static DuitNow QR image (PNG, JPG or WebP, up to 1 MB) on the public media disk, using a
 * unique filename so browsers never serve a stale cached QR on replacement, and deleting the replaced file only after the
 * database transaction commits. Bumps the display version of every active register in the outlet.
 * See docs/34-native-pos/10-customer-facing-display.md.
 */
class SaveOutletCfdQr
{
    public function __construct(private BumpCfdVersion $bumpCfdVersion) {}

    public function __invoke(User $actor, Property $property, PosOutlet $outlet, ?UploadedFile $file): PosActionResult
    {
        $diskName = (string) config('filesystems.public_media');
        $disk = Storage::disk($diskName);
        $newPath = null;
        $oldPath = null;
        $registerIds = [];

        try {
            $result = DB::transaction(function () use ($actor, $property, $outlet, $file, $diskName, &$newPath, &$oldPath, &$registerIds): PosActionResult {
                $outlet = PosOutlet::query()->where('property_id', $property->id)->whereKey($outlet->id)->lockForUpdate()->first();

                if ($outlet === null) {
                    return PosActionResult::refused('outlet_invalid');
                }

                $oldPath = $outlet->cfd_duitnow_qr_path;

                if ($file !== null) {
                    $stored = $file->storePubliclyAs('pos/cfd-qr', $outlet->id.'-'.Str::ulid().'.'.$file->extension(), $diskName);
                    $newPath = $stored === false ? null : $stored;
                }

                $outlet->forceFill(['cfd_duitnow_qr_path' => $newPath])->save();

                $registerIds = PosRegister::query()
                    ->where('pos_outlet_id', $outlet->id)
                    ->where('active', true)
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();

                activity()->performedOn($outlet)->causedBy($actor)->event('pos.outlet_cfd_qr_saved')
                    ->withProperties(['property_id' => $property->id, 'path' => $newPath])
                    ->log($file === null ? 'POS customer display DuitNow QR removed' : 'POS customer display DuitNow QR uploaded');

                return PosActionResult::saved($outlet);
            });
        } catch (Throwable $exception) {
            if ($newPath !== null) {
                $disk->delete($newPath);
            }

            throw $exception;
        }

        if (! $result->isRefused()) {
            if ($oldPath !== null && $oldPath !== $newPath) {
                $disk->delete($oldPath);
            }

            foreach ($registerIds as $registerId) {
                ($this->bumpCfdVersion)($registerId);
            }
        }

        return $result;
    }
}

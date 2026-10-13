<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Platform\ExportPartnerTemporaryPasswords;
use App\Actions\Platform\ResetPartnerTemporaryPassword;
use App\Data\Staff\TemporaryPasswordMessageData;
use App\Enums\TemporaryPasswordExportFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExportPartnerTemporaryPasswordsRequest;
use App\Http\Requests\Admin\PartnerTemporaryPasswordUsersRequest;
use App\Models\User;
use App\Services\Staff\TemporaryPasswordFile;
use App\Services\Staff\TemporaryPasswordMessage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PartnerTemporaryPasswordController extends Controller
{
    /**
     * The copy message for the chosen partner users who have an unexpired temp password.
     */
    public function message(PartnerTemporaryPasswordUsersRequest $request, ExportPartnerTemporaryPasswords $exportTemporaryPasswords, TemporaryPasswordMessage $message): JsonResponse
    {
        $slips = $exportTemporaryPasswords($request->user(), $this->chosenUsers($request), TemporaryPasswordExportFormat::Message);

        return response()->json(new TemporaryPasswordMessageData(
            text: $message->for($slips),
            count: $slips->count(),
            skipped: count($request->validated('user_ids')) - $slips->count(),
        ));
    }

    /**
     * A CSV, XLSX or PDF file of the chosen partner users who have an unexpired temp password. The `X-Skipped`
     * header counts the others.
     */
    public function export(ExportPartnerTemporaryPasswordsRequest $request, ExportPartnerTemporaryPasswords $exportTemporaryPasswords, TemporaryPasswordFile $file): BinaryFileResponse
    {
        $format = $request->exportFormat();
        $slips = $exportTemporaryPasswords($request->user(), $this->chosenUsers($request), $format);

        if ($slips->isEmpty()) {
            throw ValidationException::withMessages([
                'user_ids' => __('None of the chosen partners has an unexpired temp password.'),
            ]);
        }

        $response = response()
            ->download($file->write($slips, $format, __('Partner')), 'temp-passwords-partners.'.$format->value)
            ->deleteFileAfterSend();

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Skipped', (string) (count($request->validated('user_ids')) - $slips->count()));

        return $response;
    }

    /**
     * Give the chosen partner users new temp passwords. Suspended partners are skipped.
     */
    public function reset(PartnerTemporaryPasswordUsersRequest $request, ResetPartnerTemporaryPassword $resetTemporaryPassword): RedirectResponse
    {
        $admin = $request->user();
        $reset = $this->chosenUsers($request)
            ->filter(fn (User $user): bool => $resetTemporaryPassword($admin, $user))
            ->count();
        $skipped = count($request->validated('user_ids')) - $reset;

        Inertia::flash('toast', match (true) {
            $reset === 0 => ['type' => 'error', 'message' => __('No temp password was reset. Suspended partners are skipped.')],
            $skipped > 0 => ['type' => 'success', 'message' => __('New temp password for :count partners. :skipped skipped.', ['count' => $reset, 'skipped' => $skipped])],
            default => ['type' => 'success', 'message' => __('New temp password for :count partners.', ['count' => $reset])],
        });

        return back(fallback: route('admin.partners.index'));
    }

    /**
     * @return Collection<int, User>
     */
    private function chosenUsers(PartnerTemporaryPasswordUsersRequest $request): Collection
    {
        return User::query()->whereKey($request->validated('user_ids'))->with('partner')->orderBy('name')->get();
    }
}

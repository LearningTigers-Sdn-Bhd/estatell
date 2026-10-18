<?php

namespace App\Http\Controllers\CityLedger;

use App\Actions\CityLedger\ExportCompanyTemporaryPasswords;
use App\Actions\CityLedger\ResendCompanyLinkInvitation;
use App\Actions\CityLedger\ResetCompanyUserTemporaryPassword;
use App\Data\Staff\TemporaryPasswordMessageData;
use App\Enums\TemporaryPasswordExportFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\CityLedger\CompanyPortalLoginUsersRequest;
use App\Http\Requests\CityLedger\ExportCompanyPortalLoginsRequest;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Property;
use App\Services\Staff\TemporaryPasswordFile;
use App\Services\Staff\TemporaryPasswordMessage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CompanyPortalLoginController extends Controller
{
    /**
     * The copy message for the company's login, when it has an unexpired temp password.
     */
    public function message(Request $request, Property $property, Company $company, ExportCompanyTemporaryPasswords $export, TemporaryPasswordMessage $message): JsonResponse
    {
        $links = CompanyUser::query()->active()->where('company_id', $company->id)->with('company', 'user')->get();
        $slips = $export($property, $request->user(), $links, TemporaryPasswordExportFormat::Message);

        return response()->json(new TemporaryPasswordMessageData(
            text: $message->for($slips),
            count: $slips->count(),
            skipped: 1 - $slips->count(),
        ));
    }

    /**
     * A new temp password for the company's login. Refused once the user has set their own password or is linked
     * at another property.
     */
    public function reset(Request $request, Property $property, Company $company, ResetCompanyUserTemporaryPassword $resetPassword): RedirectResponse
    {
        if ($resetPassword($request->user(), $company)) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('New temp password made. Use Copy message to share it.')]);
            Inertia::flash('portalLoginCreated', true);
        } else {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The temp password cannot be reset. The login already has its own password, or it also works at another property.')]);
        }

        return to_route('finance.city-ledger.companies.portal', ['property' => $property, 'company' => $company]);
    }

    /**
     * Send the Pending invitation again.
     */
    public function resend(Request $request, Property $property, Company $company, ResendCompanyLinkInvitation $resend): RedirectResponse
    {
        Inertia::flash('toast', $resend($request->user(), $company)
            ? ['type' => 'success', 'message' => __('Invitation sent again.')]
            : ['type' => 'error', 'message' => __('There is no invitation to send again.')]);

        return to_route('finance.city-ledger.companies.portal', ['property' => $property, 'company' => $company]);
    }

    /**
     * The copy message for the chosen company logins (Bulk add result) with an unexpired temp password.
     */
    public function messages(CompanyPortalLoginUsersRequest $request, Property $property, ExportCompanyTemporaryPasswords $export, TemporaryPasswordMessage $message): JsonResponse
    {
        $slips = $export($property, $request->user(), $this->chosenLinks($request, $property), TemporaryPasswordExportFormat::Message);

        return response()->json(new TemporaryPasswordMessageData(
            text: $message->for($slips),
            count: $slips->count(),
            skipped: count($request->validated('user_ids')) - $slips->count(),
        ));
    }

    /**
     * A CSV, XLSX or PDF file of the chosen company logins that have an unexpired temp password. The `X-Skipped`
     * header counts the others.
     */
    public function export(ExportCompanyPortalLoginsRequest $request, Property $property, ExportCompanyTemporaryPasswords $export, TemporaryPasswordFile $file): BinaryFileResponse
    {
        $format = $request->exportFormat();
        $slips = $export($property, $request->user(), $this->chosenLinks($request, $property), $format);

        if ($slips->isEmpty()) {
            throw ValidationException::withMessages([
                'user_ids' => __('None of the chosen company logins has an unexpired temp password.'),
            ]);
        }

        $response = response()
            ->download($file->write($slips, $format, __('Company')), 'temp-passwords-companies-'.$property->code.'.'.$format->value)
            ->deleteFileAfterSend();

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Skipped', (string) (count($request->validated('user_ids')) - $slips->count()));

        return $response;
    }

    /**
     * @return Collection<int, CompanyUser>
     */
    private function chosenLinks(CompanyPortalLoginUsersRequest $request, Property $property): Collection
    {
        return CompanyUser::query()->active()
            ->where('property_id', $property->id)
            ->whereIn('user_id', $request->validated('user_ids'))
            ->with('company', 'user')
            ->get();
    }
}

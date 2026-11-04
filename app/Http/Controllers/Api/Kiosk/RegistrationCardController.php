<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Actions\Kiosk\SignStayRegistrationCard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\SignRegistrationCardRequest;
use App\Http\Resources\Kiosk\V1\KioskSignedResource;
use App\Http\Resources\Kiosk\V1\KioskTermsResource;
use App\Http\Responses\ApiError;
use App\Models\ApiToken;
use App\Services\Kiosk\KioskLanguage;
use App\Services\Kiosk\KioskRegistrationTerms;
use App\Services\Kiosk\KioskStays;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegistrationCardController extends Controller
{
    public function show(Request $request, string $id, KioskStays $stays, KioskRegistrationTerms $terms, KioskLanguage $language): KioskTermsResource|JsonResponse
    {
        $stay = ctype_digit($id) ? $stays->find($this->token($request)->property, (int) $id) : null;
        $resolved = $stay === null ? null : $terms->resolve($stay, $language->from($request));

        if ($stay === null) {
            return $this->notFound();
        }

        if ($resolved === null) {
            return ApiError::response('server_error', __('The registration terms are not ready. Please see the front desk.'), 409);
        }

        return new KioskTermsResource(['version' => $resolved->terms->version, 'text' => $terms->text($stay, $resolved)]);
    }

    public function sign(SignRegistrationCardRequest $request, string $id, KioskStays $stays, KioskLanguage $language, SignStayRegistrationCard $signCard): KioskSignedResource|JsonResponse
    {
        $stay = ctype_digit($id) ? $stays->find($this->token($request)->property, (int) $id) : null;

        return $stay === null
            ? $this->notFound()
            : new KioskSignedResource($signCard($stay, (int) $request->input('terms_version'), $request->string('signature_png')->toString(), $language->from($request)));
    }

    private function token(Request $request): ApiToken
    {
        /** @var ApiToken */
        return $request->attributes->get('api_token');
    }

    private function notFound(): JsonResponse
    {
        return ApiError::response('booking_not_found', __('We could not find that booking.'), 404);
    }
}

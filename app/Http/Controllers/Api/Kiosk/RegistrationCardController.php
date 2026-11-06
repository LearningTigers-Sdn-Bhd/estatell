<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Actions\Kiosk\SignStayRegistrationCard;
use App\Http\Requests\Kiosk\SignRegistrationCardRequest;
use App\Http\Resources\Kiosk\V1\KioskSignedResource;
use App\Http\Resources\Kiosk\V1\KioskTermsResource;
use App\Http\Responses\ApiError;
use App\Services\Kiosk\KioskLanguage;
use App\Services\Kiosk\KioskRegistrationTerms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegistrationCardController extends KioskStayController
{
    public function show(Request $request, string $id, KioskRegistrationTerms $terms, KioskLanguage $language): KioskTermsResource|JsonResponse
    {
        $stay = $this->stay($request, $id);

        if ($stay instanceof JsonResponse) {
            return $stay;
        }

        $resolved = $terms->resolve($stay, $language->from($request));

        return $resolved === null
            ? ApiError::response('server_error', __('The registration terms are not ready. Please see the front desk.'), 409)
            : new KioskTermsResource(['version' => $resolved->terms->version, 'text' => $terms->text($stay, $resolved)]);
    }

    public function sign(SignRegistrationCardRequest $request, string $id, KioskLanguage $language, SignStayRegistrationCard $signCard): KioskSignedResource|JsonResponse
    {
        $stay = $this->stay($request, $id);

        return $stay instanceof JsonResponse
            ? $stay
            : new KioskSignedResource($signCard($stay, (int) $request->input('terms_version'), $request->string('signature_png')->toString(), $language->from($request)));
    }
}

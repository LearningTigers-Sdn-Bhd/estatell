<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\PosQrSession;
use App\Models\Property;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The hotel logo for a guest's QR menu. The logo sits on the private media disk and the guest has no login, so the QR
 * token is what lets the browser read it. See docs/34-native-pos/06-kitchen-kds-and-qr.md.
 */
class QrLogoController extends Controller
{
    public function __invoke(Request $request, string $token): StreamedResponse
    {
        $session = PosQrSession::query()->where('token', $token)->with('property')->firstOrFail();
        $media = $session->property->getFirstMedia(Property::LOGO) ?? abort(404);

        return $media->toInlineResponse($request);
    }

    /** The logo link for a session's guest page, or null when the hotel has no logo. */
    public static function urlFor(PosQrSession $session): ?string
    {
        $media = $session->property->getFirstMedia(Property::LOGO);

        return $media === null ? null : route('qr.logo', ['token' => $session->token, 'v' => $media->id], false);
    }
}

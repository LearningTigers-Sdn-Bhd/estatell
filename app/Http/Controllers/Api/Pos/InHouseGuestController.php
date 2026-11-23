<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Models\PosApiClient;
use App\Services\Pos\InHouseGuestLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InHouseGuestController extends Controller
{
    public function index(Request $request, InHouseGuestLookup $lookup): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'room' => ['nullable', 'string', 'max:30'],
        ]);

        /** @var PosApiClient $client */
        $client = $request->attributes->get('pos_client');

        return response()->json(['guests' => $lookup->find($client->property, $validated['q'] ?? null, $validated['room'] ?? null)]);
    }
}

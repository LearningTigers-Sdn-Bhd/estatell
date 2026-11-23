<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Models\PosApiClient;
use App\Models\PosOutlet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OutletController extends Controller
{
    /**
     * The active outlets of the token's property, so a POS can map its terminals to outlet codes.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var PosApiClient $client */
        $client = $request->attributes->get('pos_client');

        return response()->json([
            'outlets' => PosOutlet::query()
                ->where('property_id', $client->property_id)
                ->active()
                ->orderBy('name')
                ->get()
                ->map(fn (PosOutlet $outlet): array => ['id' => $outlet->id, 'code' => $outlet->code, 'name' => $outlet->name])
                ->values(),
        ]);
    }
}

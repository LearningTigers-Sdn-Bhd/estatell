<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\CommandSearchRequest;
use App\Services\Platform\CommandSearch;
use Illuminate\Http\JsonResponse;

class CommandSearchController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(CommandSearchRequest $request, CommandSearch $search): JsonResponse
    {
        return response()->json([
            'results' => $search->forUser($request->user(), $request->validated('q')),
        ])->header('Cache-Control', 'private, no-store');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Actions\AiAssistant\CreateAiConnection;
use App\Actions\AiAssistant\RevokeAiConnection;
use App\Enums\AiScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAiConnectionRequest;
use App\Models\AiConnection;
use App\Models\Property;
use App\Services\AiAssistant\AiScopes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The chat API connections on the admin property page's Chat API tab. Estatell sets them up for the hotel, with its
 * consent. See docs/35-ai-assistant/03-places-and-routes.md#admin.
 */
class PropertyAiConnectionController extends Controller
{
    /**
     * The token is flashed once. It is not stored in plain text and is never in the URL.
     */
    public function store(StoreAiConnectionRequest $request, Property $property, AiScopes $aiScopes, CreateAiConnection $createAiConnection): RedirectResponse
    {
        abort_unless($aiScopes->isAllowed($property, AiScope::Api), 404);

        ['connection' => $connection, 'token' => $token] = $createAiConnection($request->user(), $property, (string) $request->validated('name'));

        Inertia::flash('aiConnectionToken', ['name' => $connection->name, 'token' => $token]);

        return to_route('admin.properties.chat-api', $property);
    }

    public function destroy(Request $request, Property $property, AiConnection $aiConnection, RevokeAiConnection $revokeAiConnection): RedirectResponse
    {
        Inertia::flash('toast', $revokeAiConnection($request->user(), $aiConnection)
            ? ['type' => 'success', 'message' => __('Connection revoked.')]
            : ['type' => 'error', 'message' => __('That connection is already revoked.')]);

        return to_route('admin.properties.chat-api', $property);
    }
}

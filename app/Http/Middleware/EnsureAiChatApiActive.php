<?php

namespace App\Http\Middleware;

use App\Enums\AiScope;
use App\Http\Responses\ApiError;
use App\Models\AiConnection;
use App\Services\AiAssistant\AiScopes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers `404 ai_unavailable` to the chat API while the token's property cannot use it: the AI assistant feature or the
 * Chat API switch is off, or the hotel has no OpenAI key. Put it after `api.token`, which sets the `ai_connection`
 * attribute. See docs/35-ai-assistant/03-places-and-routes.md#request.
 */
class EnsureAiChatApiActive
{
    public function __construct(private readonly AiScopes $scopes) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $connection = $request->attributes->get('ai_connection');

        if (! $connection instanceof AiConnection || ! $this->scopes->isOn($connection->property, AiScope::Api)) {
            return ApiError::response('ai_unavailable', __('The chat API is not turned on for this property.'), 404);
        }

        return $next($request);
    }
}

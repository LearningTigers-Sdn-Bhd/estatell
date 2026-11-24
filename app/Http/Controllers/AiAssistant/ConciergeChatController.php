<?php

namespace App\Http\Controllers\AiAssistant;

use App\Actions\AiAssistant\OpenAiThread;
use App\Actions\AiAssistant\ReplyInAiThread;
use App\Enums\AiPlace;
use App\Http\Controllers\Controller;
use App\Http\Requests\AiAssistant\SendChatMessageRequest;
use App\Models\AiThread;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\AiAssistant\AiThreads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Ai\Responses\StreamableAgentResponse;

/**
 * The AI assistant chat on the Concierge page: the lobby (no stay known) and a stay link (tied to that stay). Guarded by
 * `concierge` and `ai-assistant:concierge`. Each place reads its thread back as JSON and answers a message as a stream.
 * See docs/34-ai-assistant/03-places-and-routes.md#concierge-lobby-and-stay.
 */
class ConciergeChatController extends Controller
{
    public function lobbyMessages(Request $request, Property $property, AiThreads $threads): JsonResponse
    {
        $threadId = $request->query('thread');
        $thread = is_string($threadId) && str()->isUuid($threadId) ? $threads->current($property, AiPlace::ConciergeLobby, threadId: $threadId) : null;

        return response()->json(['thread' => $thread?->id, 'messages' => $thread === null ? [] : $threads->uiMessages($thread)]);
    }

    public function lobbySend(SendChatMessageRequest $request, Property $property, OpenAiThread $open, ReplyInAiThread $reply): StreamableAgentResponse|JsonResponse
    {
        $thread = $open($property, AiPlace::ConciergeLobby, threadId: $request->threadId(), language: $request->language());

        if ($thread === null) {
            return response()->json(['message' => __('This chat has ended. Start a new one.')], 409);
        }

        return $reply($thread, $request->chat());
    }

    public function stayMessages(Request $request, string $token, AiThreads $threads): JsonResponse
    {
        $stay = $this->stay($request);
        $thread = $threads->current($stay->property, AiPlace::ConciergeStay, $stay);

        return response()->json(['thread' => $thread?->id, 'messages' => $thread === null ? [] : $threads->uiMessages($thread)]);
    }

    public function staySend(SendChatMessageRequest $request, string $token, OpenAiThread $open, ReplyInAiThread $reply): StreamableAgentResponse
    {
        $stay = $this->stay($request);

        /** @var AiThread $thread A stay always gets a thread. */
        $thread = $open($stay->property, AiPlace::ConciergeStay, $stay, language: $request->language());

        return $reply($thread, $request->chat());
    }

    private function stay(Request $request): ReservationUnit
    {
        return $request->attributes->get('concierge.stay');
    }
}

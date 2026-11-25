<?php

namespace App\Http\Controllers\Api\Ai;

use App\Actions\AiAssistant\AnswerApiMessage;
use App\Actions\AiAssistant\FindOrStartApiThread;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SendAiApiMessageRequest;
use App\Http\Responses\ApiError;
use App\Models\AiConnection;
use Illuminate\Http\JsonResponse;

/**
 * The chat API for n8n: one guest message in, the assistant's reply out as plain JSON.
 * See docs/35-ai-assistant/03-places-and-routes.md#chat-api-for-n8n.
 */
class MessageController extends Controller
{
    public function store(SendAiApiMessageRequest $request, FindOrStartApiThread $findThread, AnswerApiMessage $answer): JsonResponse
    {
        /** @var AiConnection $connection */
        $connection = $request->attributes->get('ai_connection');

        $thread = $findThread($connection, $request->channel(), $request->string('thread')->toString(), $request->guestName());
        $result = $answer($thread, $request->string('message')->toString());

        if ($result === null) {
            return ApiError::response('ai_failed', __('The assistant could not answer. Try again in a moment.'), 503);
        }

        return response()->json(['thread_id' => $thread->id, 'reply' => $result['reply'], 'escalated' => $result['escalated']]);
    }
}

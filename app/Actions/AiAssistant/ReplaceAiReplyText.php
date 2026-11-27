<?php

namespace App\Actions\AiAssistant;

use App\Models\AiThread;
use Illuminate\Support\Facades\DB;

/**
 * Replace the text of the thread's newest reply in the SDK's conversation, after the reply check changed what the
 * guest was sent. The model and the AI chats inbox then see what the guest saw, not the notes that were held back. Tool
 * calls stay as they were. See docs/35-ai-assistant/03-places-and-routes.md#reply-check.
 */
class ReplaceAiReplyText
{
    public function __invoke(AiThread $thread, string $text): void
    {
        $table = DB::table((string) config('ai.conversations.tables.messages', 'agent_conversation_messages'));
        $row = $table->clone()
            ->where('conversation_id', $thread->agent_conversation_id)
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->first(['id', 'steps']);

        if ($row === null) {
            return;
        }

        /** @var list<array<string, mixed>> $steps */
        $steps = json_decode((string) $row->steps, true) ?: [];
        $last = array_key_last($steps);

        foreach ($steps as $index => $step) {
            $steps[$index]['content'] = $index === $last ? $text : '';
        }

        $table->clone()->where('id', $row->id)->update([
            'content' => $text,
            'steps' => json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}

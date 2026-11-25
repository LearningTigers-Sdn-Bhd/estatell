<?php

namespace App\Data\AiAssistant;

use App\Models\AiThread;
use Spatie\LaravelData\Data;

/**
 * The open thread in the AI chats inbox Sheet: its row, who resolved the last handover, and the chat in the shape the AI
 * Elements messages read. `messages` is empty once the 90-day purge has run.
 */
class AiThreadData extends Data
{
    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public function __construct(
        public AiThreadRowData $row,
        public ?string $resolvedAt,
        public ?string $resolvedByName,
        public bool $canResolve,
        public array $messages,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public static function fromModel(AiThread $thread, array $messages, bool $canResolve): self
    {
        return new self(
            row: AiThreadRowData::fromModel($thread),
            resolvedAt: $thread->resolved_at?->toIso8601String(),
            resolvedByName: $thread->resolvedBy?->name,
            canResolve: $canResolve,
            messages: $messages,
        );
    }
}

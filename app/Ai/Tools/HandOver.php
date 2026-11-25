<?php

namespace App\Ai\Tools;

use App\Actions\AiAssistant\EscalateAiThread;
use App\Models\AiThread;
use App\Services\AiAssistant\AiHandoverMessage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Hand the thread over to staff when the assistant cannot help, is unsure, or the guest asks for a person. It works on
 * the thread it was given only. See docs/35-ai-assistant/02-agent-and-tools.md#handover.
 */
class HandOver implements Tool
{
    public function __construct(
        private readonly AiThread $thread,
        private readonly EscalateAiThread $escalate,
        private readonly AiHandoverMessage $handover,
    ) {}

    public function description(): Stringable|string
    {
        return 'Pass the conversation to the front desk. Use it when you cannot answer, are unsure, the guest asks for a person, or asks to cancel, change or refund.';
    }

    public function handle(Request $request): Stringable|string
    {
        $summary = trim((string) $request->string('summary'));

        ($this->escalate)($this->thread, $summary !== '' ? $summary : (string) $request->string('reason'));

        return 'Handed over to the front desk. Now tell the guest: '.$this->handover->forProperty($this->thread->property);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'reason' => $schema->string()->description('Why you are handing over, in a few words.')->required(),
            'summary' => $schema->string()->description('For the front desk: what the guest needs, in one or two short sentences. No card numbers, passwords or ID numbers.')->required(),
        ];
    }
}

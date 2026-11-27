<?php

namespace App\Ai\Tools;

use App\Actions\AiAssistant\AddToAiHandover;
use App\Actions\AiAssistant\EscalateAiThread;
use App\Enums\AiThreadStatus;
use App\Models\AiThread;
use App\Services\AiAssistant\AiHandoverMessage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Hand the thread over to staff when the assistant cannot help, is unsure, or the guest asks for a person. It works on
 * the thread it was given only. A thread that is already handed over gets the new question added to its summary, and
 * the guest gets a short line instead of the handover message again. See docs/35-ai-assistant/02-agent-and-tools.md#handover.
 */
class HandOver implements Tool
{
    public function __construct(
        private readonly AiThread $thread,
        private readonly EscalateAiThread $escalate,
        private readonly AddToAiHandover $addToHandover,
        private readonly AiHandoverMessage $handover,
    ) {}

    public function description(): Stringable|string
    {
        return 'Pass the conversation to the front desk. Use it when you cannot answer, are unsure, the guest asks for a person, or asks to cancel, change or refund a stay that is already booked. New dates for a quote link you sent are not a change to a booking: check rooms again instead.';
    }

    public function handle(Request $request): Stringable|string
    {
        $summary = trim((string) $request->string('summary'));
        $summary = $summary !== '' ? $summary : (string) $request->string('reason');

        if ($this->thread->status === AiThreadStatus::Escalated) {
            ($this->addToHandover)($this->thread, $summary);

            return 'This chat is already with the front desk, and this question is now in the note for them. Do not send the handover message again. Tell the guest in one short sentence, in the language they write in, that the front desk has this question too and will reply soon.';
        }

        ($this->escalate)($this->thread, $summary);

        return 'Handed over to the front desk. Now send the guest the handover message, in the language they write in: '.$this->handover->forProperty($this->thread->property);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'reason' => $schema->string()->description('Why you are handing over, in a few words.')->required(),
            'summary' => $schema->string()->description('For the front desk: what the guest needs, in one or two short sentences. No card numbers, passwords or ID numbers.')->required(),
        ];
    }
}

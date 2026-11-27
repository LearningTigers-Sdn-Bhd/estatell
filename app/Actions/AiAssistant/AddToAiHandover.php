<?php

namespace App\Actions\AiAssistant;

use App\Enums\AiThreadStatus;
use App\Models\AiThread;
use Illuminate\Support\Str;

/**
 * Add a later question to a thread that is already handed over: the new summary goes on a new line of the handover
 * summary, so the front desk sees every question the guest asked. Staff are not alerted again. Returns false without
 * writing when the thread is not handed over, or the summary is empty or already there.
 * See docs/35-ai-assistant/02-agent-and-tools.md#handover.
 */
class AddToAiHandover
{
    private const SUMMARY_LIMIT = 1000;

    public function __invoke(AiThread $thread, string $summary): bool
    {
        $summary = trim($summary);
        $current = (string) $thread->handover_summary;

        if ($thread->status !== AiThreadStatus::Escalated || $summary === '' || str_contains($current, $summary)) {
            return false;
        }

        $thread->update([
            'handover_summary' => Str::limit(trim($current."\n".$summary), self::SUMMARY_LIMIT),
        ]);

        return true;
    }
}

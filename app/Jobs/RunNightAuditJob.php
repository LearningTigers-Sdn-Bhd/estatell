<?php

namespace App\Jobs;

use App\Actions\NightAudit\RunNightAudit;
use App\Enums\AuditTrigger;
use App\Models\Property;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class RunNightAuditJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * An audit that fails is recorded on its row and resumed by the next run, so the job never retries itself.
     */
    public int $tries = 1;

    public function __construct(
        public Property $property,
        public AuditTrigger $trigger,
        public ?User $actor = null,
        public bool $force = false,
        public ?string $forceReason = null,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->property->id;
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->property->id))->dontRelease()];
    }

    /**
     * Run the audit with `RunNightAudit`. A refusal or a failed step is already in the audit rows, so there is nothing to report here.
     */
    public function handle(RunNightAudit $runNightAudit): void
    {
        $runNightAudit($this->property, $this->trigger, $this->actor, $this->force, $this->forceReason);
    }
}

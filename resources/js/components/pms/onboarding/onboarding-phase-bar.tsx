import { Menu } from 'lucide-react';
import { OnboardingStepLink } from '@/components/pms/onboarding/onboarding-step-link';
import { OnboardingStateIcon } from '@/components/pms/onboarding/onboarding-state-icon';
import {
    type OnboardingPhase,
    type OnboardingProgress,
    listedSteps,
    phaseOf,
    stateWord,
} from '@/components/pms/onboarding/onboarding-steps';
import { Button } from '@/components/ui/button';
import {
    Progress,
    ProgressIndicator,
    ProgressTrack,
} from '@/components/ui/progress';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { cn } from 'cn';
import { useState } from 'react';

/**
 * The phase bar and the stepper of the current phase. At phone width the bar is one line with a progress bar and a
 * button that opens every section in a sheet.
 */
export function OnboardingPhaseBar({
    progress,
    step,
}: {
    progress: OnboardingProgress;
    step: string | null;
}) {
    const phase = phaseOf(progress, step);
    const steps = listedSteps(progress);
    const position = steps.findIndex((item) => item.value === step) + 1;
    const percent =
        progress.requiredTotal === 0
            ? 0
            : Math.round(
                  (progress.requiredDone / progress.requiredTotal) * 100,
              );

    return (
        <div className="shrink-0 border-b bg-background">
            <div className="hidden md:block">
                <nav aria-label="Phases" className="overflow-x-auto px-4 py-2">
                    <ol className="flex items-center">
                        {progress.phases.map((item, index) => (
                            <PhaseItem
                                key={item.value}
                                phase={item}
                                current={item.value === phase?.value}
                                first={index === 0}
                            />
                        ))}
                    </ol>
                </nav>
                {phase !== null && (
                    <nav
                        aria-label={`${phase.label} sections`}
                        className="border-t bg-muted/40 px-4"
                    >
                        <ol className="flex flex-wrap items-center">
                            {phase.steps.map((item) => (
                                <li key={item.value}>
                                    <OnboardingStepLink
                                        step={item}
                                        current={item.value === step}
                                        variant="tab"
                                    />
                                </li>
                            ))}
                        </ol>
                    </nav>
                )}
            </div>

            <div className="flex items-center gap-3 px-4 py-2 md:hidden">
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">
                        {phase?.label} · {position} of {steps.length}
                    </p>
                    <Progress
                        value={percent}
                        aria-label="Required sections done"
                        className="mt-1.5"
                    >
                        <ProgressTrack>
                            <ProgressIndicator />
                        </ProgressTrack>
                    </Progress>
                </div>
                <OnboardingSectionsSheet progress={progress} step={step} />
            </div>
        </div>
    );
}

function PhaseItem({
    phase,
    current,
    first,
}: {
    phase: OnboardingPhase;
    current: boolean;
    first: boolean;
}) {
    return (
        <li className={cn('min-w-0 flex-1', !first && '-ml-2')}>
            <OnboardingStepLink
                step={{
                    ...phase.steps[0],
                    label: phase.label,
                    state: phase.state,
                    flagged: phase.steps.some((item) => item.flagged),
                }}
                current={current}
                variant="segment"
                first={first}
            />
        </li>
    );
}

/**
 * Every phase and section with its state, in a bottom sheet. Phone width only.
 */
function OnboardingSectionsSheet({
    progress,
    step,
}: {
    progress: OnboardingProgress;
    step: string | null;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Sheet open={open} onOpenChange={setOpen}>
            <SheetTrigger
                render={
                    <Button
                        variant="outline"
                        size="icon"
                        aria-label="All sections"
                    />
                }
            >
                <Menu />
            </SheetTrigger>
            <SheetContent
                side="bottom"
                className="max-h-[85svh] overflow-y-auto"
            >
                <SheetHeader>
                    <SheetTitle>All sections</SheetTitle>
                    <SheetDescription>
                        Locked sections open as a preview.
                    </SheetDescription>
                </SheetHeader>
                <div className="flex flex-col gap-4 px-4 pb-6">
                    {progress.phases.map((phase) => (
                        <section
                            key={phase.value}
                            className="flex flex-col gap-1"
                        >
                            <h3 className="flex items-center gap-2 text-sm font-semibold">
                                <OnboardingStateIcon
                                    state={phase.state}
                                    number={phase.steps[0].number}
                                />
                                {phase.label}
                                <span className="sr-only">
                                    {stateWord[phase.state]}
                                </span>
                            </h3>
                            <ul className="flex flex-col">
                                {phase.steps.map((item) => (
                                    <li key={item.value}>
                                        <OnboardingStepLink
                                            step={item}
                                            current={item.value === step}
                                            onNavigate={() => setOpen(false)}
                                        />
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ))}
                </div>
            </SheetContent>
        </Sheet>
    );
}

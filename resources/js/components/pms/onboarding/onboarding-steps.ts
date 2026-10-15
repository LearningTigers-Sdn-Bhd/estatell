export type OnboardingProgress = App.Data.Onboarding.OnboardingProgressData;
export type OnboardingPhase = App.Data.Onboarding.OnboardingPhaseData;
export type OnboardingStep = App.Data.Onboarding.OnboardingStepData;

/**
 * Props that pages set on the onboarding layout with setLayoutProps. `step` is the value of the step on screen.
 * `footer` is `auto` for a form step (the footer follows the step's lock) or `back` for a page with no save.
 * `formId` names the form that the footer's Save & continue submits.
 */
export type OnboardingLayoutProps = {
    step: string | null;
    footer: 'auto' | 'back';
    formId: string | null;
    processing: boolean;
    /** The content column width. Omitted is the narrow default (45rem). */
    width?: OnboardingWidth;
};

export type OnboardingWidth = '5xl' | '7xl' | '8xl';

/** Full class names, so Tailwind can see them. The footer uses the same width as the content. */
export const widthClass: Record<OnboardingWidth | 'default', string> = {
    default: 'max-w-180',
    '5xl': 'max-w-5xl',
    '7xl': 'max-w-7xl',
    '8xl': 'max-w-8xl',
};

/** Every listed step, in wizard order. */
export function listedSteps(progress: OnboardingProgress): OnboardingStep[] {
    return progress.phases.flatMap((phase) => phase.steps);
}

/** The phase that holds the step on screen, or the first phase when the step is not listed. */
export function phaseOf(
    progress: OnboardingProgress,
    value: string | null,
): OnboardingPhase | null {
    return (
        progress.phases.find((phase) =>
            phase.steps.some((step) => step.value === value),
        ) ??
        progress.phases[0] ??
        null
    );
}

/** The listed steps before and after the step on screen. */
export function neighbours(
    progress: OnboardingProgress,
    value: string | null,
): {
    current: OnboardingStep | null;
    previous: OnboardingStep | null;
    next: OnboardingStep | null;
} {
    const steps = listedSteps(progress);
    const index = steps.findIndex((step) => step.value === value);

    if (index === -1) {
        return { current: null, previous: null, next: null };
    }

    return {
        current: steps[index],
        previous: steps[index - 1] ?? null,
        next: steps[index + 1] ?? null,
    };
}

/** The words a screen reader hears for a state. The icon is never the only signal. */
export const stateWord: Record<string, string> = {
    done: 'Done',
    locked: 'Locked',
    needs_attention: 'Needs attention',
    in_progress: 'In progress',
    not_started: 'Not started',
};

import { Link } from '@inertiajs/react';
import {
    Circle,
    CircleCheck,
    CircleX,
    Flag,
    Lock,
    Minus,
    TriangleAlert,
} from 'lucide-react';
import {
    phaseIcon,
    stateWord,
} from '@/components/pms/onboarding/onboarding-steps';
import { buttonVariants } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

type Phase = App.Data.Onboarding.GoLiveReviewPhaseData;
type Row = App.Data.Onboarding.GoLiveReviewRowData;

/**
 * Lets the admin tick the rows to flag for the owner. Without it, each row links to its section for the owner.
 */
export type ReviewFlagging = {
    flagged: ReadonlySet<string>;
    onToggle: (value: string, flagged: boolean) => void;
};

/**
 * One phase of the Review page: a small card with a bordered list of its sections. The owner's Review links each row
 * to its section. The admin review shows the extra detail lines and a tick to flag the row instead.
 */
export function ReviewPhaseCard({
    phase,
    flagging,
}: {
    phase: Phase;
    flagging?: ReviewFlagging;
}) {
    const Icon = phaseIcon[phase.value];

    return (
        <Card size="sm" role="group" aria-labelledby={`phase-${phase.value}`}>
            <div className="flex items-center gap-2 px-3">
                {Icon && (
                    <Icon
                        aria-hidden
                        className="size-4 shrink-0 text-muted-foreground"
                    />
                )}
                <h2 id={`phase-${phase.value}`} className="font-medium">
                    {phase.label}
                </h2>
            </div>
            <div className="mx-3 rounded-lg border">
                <ul className="divide-y">
                    {phase.rows.map((row) => (
                        <ReviewRow
                            key={row.value}
                            row={row}
                            flagging={flagging}
                        />
                    ))}
                </ul>
            </div>
        </Card>
    );
}

function ReviewRow({ row, flagging }: { row: Row; flagging?: ReviewFlagging }) {
    const word = row.skipped ? 'Skipped' : (stateWord[row.state] ?? '');
    const flagged = flagging ? flagging.flagged.has(row.value) : row.flagged;

    return (
        <li className="flex flex-col gap-2 px-3 py-3 md:grid md:grid-cols-[minmax(0,11rem)_minmax(0,1fr)_auto] md:items-center md:gap-4">
            <div className="flex items-center gap-2">
                <StateIcon row={row} />
                <span className="font-medium">{row.label}</span>
                {flagged && (
                    <Flag
                        aria-hidden
                        className="size-3.5 shrink-0 text-warning"
                    />
                )}
                <span className="sr-only">
                    {word}
                    {flagged ? ', flagged' : ''}
                </span>
            </div>
            <div className="flex flex-col gap-1 text-sm text-pretty text-muted-foreground">
                {row.flagged && !flagging && (
                    <p className="font-medium text-warning">
                        Flagged by support
                    </p>
                )}
                {row.summary !== '' && <p>{row.summary}</p>}
                {row.details.map((line) => (
                    <p key={line} className="text-xs">
                        {line}
                    </p>
                ))}
            </div>
            {flagging ? (
                <Label className="flex items-center gap-2 text-sm font-normal">
                    <Checkbox
                        checked={flagged}
                        onCheckedChange={(checked) =>
                            flagging.onToggle(row.value, checked === true)
                        }
                    />
                    Flag
                    <span className="sr-only"> {row.label} for the owner</span>
                </Label>
            ) : (
                <Link
                    href={row.actionUrl}
                    className={buttonVariants({
                        variant:
                            row.actionLabel === 'Edit' ? 'ghost' : 'outline',
                        size: 'sm',
                    })}
                >
                    {row.actionLabel}
                    <span className="sr-only"> {row.label}</span>
                </Link>
            )}
        </li>
    );
}

function StateIcon({ row }: { row: Row }) {
    if (row.skipped) {
        return (
            <Minus
                aria-hidden
                className="size-4 shrink-0 text-muted-foreground"
            />
        );
    }

    switch (row.state) {
        case 'done':
            return (
                <CircleCheck
                    aria-hidden
                    className="size-4 shrink-0 text-success"
                />
            );
        case 'locked':
            return (
                <Lock
                    aria-hidden
                    className="size-4 shrink-0 text-muted-foreground"
                />
            );
        case 'needs_attention':
            return (
                <TriangleAlert
                    aria-hidden
                    className="size-4 shrink-0 text-warning"
                />
            );
        default:
            return row.isRequired ? (
                <CircleX
                    aria-hidden
                    className="size-4 shrink-0 text-destructive"
                />
            ) : (
                <Circle
                    aria-hidden
                    className="size-4 shrink-0 text-muted-foreground"
                />
            );
    }
}

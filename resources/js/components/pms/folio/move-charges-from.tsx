import { Checkbox } from '@/components/ui/checkbox';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { MoneyText } from '@/components/pms/money-text';
import { shortDate } from '@/components/pms/folio/move-charges-format';

type Folio = App.Data.Folio.MoveFolioData;
type Preview = App.Data.Folio.MovePreviewData;
type Schedule = App.Data.Folio.MoveScheduleData;

export type ScheduleChoices = Record<number, 'room' | 'all'>;

/** The posted balance of a folio, with "before → after" while the figures are for the shown target. */
export function BalanceFigure({
    folioId,
    preview,
    showAfter,
    currency,
    label = 'Balance',
}: {
    folioId: number;
    preview: Preview;
    showAfter: boolean;
    currency: App.Data.Property.CurrencyData | null;
    label?: string;
}) {
    const figure = preview.balances.find((one) => one.folioId === folioId);

    if (!figure) {
        return null;
    }

    return (
        <p className="text-sm text-muted-foreground">
            {label}{' '}
            <MoneyText amount={figure.before} currency={currency} plain />
            {showAfter && (
                <>
                    {' → '}
                    <MoneyText
                        amount={figure.after}
                        currency={currency}
                        plain
                    />
                </>
            )}
        </p>
    );
}

function ScheduleChoice({
    schedule,
    value,
    onChange,
    showRoom,
}: {
    schedule: Schedule;
    value: 'room' | 'all' | undefined;
    onChange: (scope: 'room' | 'all' | undefined) => void;
    showRoom: boolean;
}) {
    return (
        <div className="flex flex-col gap-2">
            {showRoom && (
                <p className="text-sm font-medium">Room {schedule.label}</p>
            )}
            {schedule.choices.map((choice) => {
                const id = `schedule-${schedule.unitId}-${choice.scope}`;
                const scope = choice.scope as 'room' | 'all';

                return (
                    <div key={choice.scope} className="flex items-start gap-2">
                        <Checkbox
                            id={id}
                            checked={value === scope}
                            onCheckedChange={(checked) =>
                                onChange(checked ? scope : undefined)
                            }
                            className="mt-0.5"
                        />
                        <Label
                            htmlFor={id}
                            className="flex flex-col items-start gap-0.5 font-normal"
                        >
                            <span className="font-medium">{choice.title}</span>
                            <span className="text-muted-foreground">
                                {choice.detail}
                            </span>
                        </Label>
                    </div>
                );
            })}
        </div>
    );
}

function ScheduledBlock({
    schedules,
    choices,
    onChoice,
}: {
    schedules: Schedule[];
    choices: ScheduleChoices;
    onChoice: (unitId: number, scope: 'room' | 'all' | undefined) => void;
}) {
    if (schedules.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-3 border-t pt-3">
            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                Scheduled · not charged yet
            </p>
            {schedules.map((schedule) => (
                <ScheduleChoice
                    key={schedule.unitId}
                    schedule={schedule}
                    value={choices[schedule.unitId]}
                    onChange={(scope) => onChoice(schedule.unitId, scope)}
                    showRoom
                />
            ))}
        </div>
    );
}

/**
 * The From column: one group for each Open folio except the target, with its movable charges, then the scheduled
 * choices of each room. Quick select ticks charges; it never changes the target.
 */
export function MoveChargesFrom({
    sources,
    ticked,
    onToggle,
    onQuickSelect,
    preview,
    previewCurrent,
    choices,
    onChoice,
    currency,
}: {
    sources: Folio[];
    ticked: number[];
    onToggle: (lineId: number, checked: boolean) => void;
    onQuickSelect: (mode: 'room' | 'all' | 'clear') => void;
    preview: Preview;
    previewCurrent: boolean;
    choices: ScheduleChoices;
    onChoice: (unitId: number, scope: 'room' | 'all' | undefined) => void;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const schedules = previewCurrent ? preview.schedules : [];
    const sourceIds = sources.map((source) => source.id);
    const loose = schedules.filter(
        (schedule) =>
            schedule.fromFolioId === null ||
            !sourceIds.includes(schedule.fromFolioId),
    );

    return (
        <Card>
            <CardHeader className="flex flex-wrap items-center justify-between gap-2">
                <CardTitle className="font-heading text-sm tracking-wide uppercase">
                    From
                </CardTitle>
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm text-muted-foreground">
                        Quick select
                    </span>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => onQuickSelect('room')}
                    >
                        Room
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => onQuickSelect('all')}
                    >
                        All charges
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => onQuickSelect('clear')}
                    >
                        Clear
                    </Button>
                </div>
            </CardHeader>
            <CardContent className="flex flex-col gap-6">
                {sources.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        There is no other open folio to move charges from.
                    </p>
                )}
                {sources.map((source) => (
                    <section key={source.id} className="flex flex-col gap-3">
                        <div className="flex flex-col">
                            <h2 className="font-medium">{source.label}</h2>
                            <BalanceFigure
                                folioId={source.id}
                                preview={preview}
                                showAfter={previewCurrent}
                                currency={currency}
                            />
                        </div>
                        {source.charges.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No charges to move
                            </p>
                        ) : (
                            <ul className="flex flex-col gap-2">
                                {source.charges.map((charge) => {
                                    const id = `charge-${charge.lineId}`;

                                    return (
                                        <li
                                            key={charge.lineId}
                                            className="flex items-start gap-2 text-sm"
                                        >
                                            <Checkbox
                                                id={id}
                                                checked={ticked.includes(
                                                    charge.lineId,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    onToggle(
                                                        charge.lineId,
                                                        checked,
                                                    )
                                                }
                                                className="mt-0.5"
                                            />
                                            <Label
                                                htmlFor={id}
                                                className="grid flex-1 grid-cols-[4rem_minmax(0,1fr)_auto] items-baseline gap-x-2 font-normal"
                                            >
                                                <span className="text-muted-foreground tabular-nums">
                                                    {shortDate(charge.date)}
                                                </span>
                                                <span className="min-w-0 truncate">
                                                    {charge.description}
                                                    {charge.unit && (
                                                        <span className="text-muted-foreground">
                                                            {' · '}
                                                            {charge.unit}
                                                        </span>
                                                    )}
                                                </span>
                                                <span className="text-right">
                                                    <MoneyText
                                                        amount={charge.amount}
                                                        currency={currency}
                                                        plain
                                                    />
                                                    {charge.children.map(
                                                        (child, index) => (
                                                            <span
                                                                key={index}
                                                                className="ml-1 text-muted-foreground"
                                                            >
                                                                {' +'}
                                                                {
                                                                    child.description
                                                                }{' '}
                                                                <MoneyText
                                                                    amount={
                                                                        child.amount
                                                                    }
                                                                    currency={
                                                                        currency
                                                                    }
                                                                    plain
                                                                />
                                                            </span>
                                                        ),
                                                    )}
                                                </span>
                                            </Label>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                        <ScheduledBlock
                            schedules={schedules.filter(
                                (schedule) =>
                                    schedule.fromFolioId === source.id,
                            )}
                            choices={choices}
                            onChoice={onChoice}
                        />
                    </section>
                ))}
                {loose.length > 0 && (
                    <section className="flex flex-col gap-3">
                        <ScheduledBlock
                            schedules={loose}
                            choices={choices}
                            onChoice={onChoice}
                        />
                    </section>
                )}
            </CardContent>
        </Card>
    );
}

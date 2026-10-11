import { Info, X } from 'lucide-react';
import { EnumSelect } from '@/components/pms/enum-select';
import { BalanceFigure } from '@/components/pms/folio/move-charges-from';
import { shortDate } from '@/components/pms/folio/move-charges-format';
import { MoneyText } from '@/components/pms/money-text';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FieldError } from '@/components/ui/field';
import { Skeleton } from '@/components/ui/skeleton';

type Folio = App.Data.Folio.MoveFolioData;
type Preview = App.Data.Folio.MovePreviewData;

/**
 * The To column: the target (a Select of the Open folios), its balance and projected figures, the ticked charges
 * grouped by source folio (each with a button that unticks it), the scheduled charges that land here, the notes, and the
 * charges already on the target (read only).
 */
export function MoveChargesTo({
    folios,
    target,
    onTargetChange,
    ticked,
    onUntick,
    preview,
    previewCurrent,
    currency,
    error,
}: {
    folios: Folio[];
    target: Folio;
    onTargetChange: (id: number) => void;
    ticked: number[];
    onUntick: (lineId: number) => void;
    preview: Preview;
    /** True when the shown preview is for the latest choice of charges and rooms. */
    previewCurrent: boolean;
    currency: App.Enums.Currency | null;
    error?: string;
}) {
    const forTarget = preview.targetId === target.id;
    const groups = folios
        .filter((folio) => folio.id !== target.id)
        .map((folio) => ({
            folio,
            charges: folio.charges.filter((charge) =>
                ticked.includes(charge.lineId),
            ),
        }))
        .filter((group) => group.charges.length > 0);
    const incoming = forTarget ? preview.incoming : [];
    const empty = groups.length === 0 && incoming.length === 0;

    return (
        <Card>
            <CardHeader className="flex flex-wrap items-center justify-between gap-2">
                <CardTitle className="font-heading text-sm tracking-wide uppercase">
                    To
                </CardTitle>
                <div className="min-w-0 flex-1 basis-56 sm:max-w-sm">
                    <EnumSelect
                        aria-label="Move charges to"
                        options={folios.map((folio) => ({
                            value: String(folio.id),
                            label: folio.label,
                        }))}
                        value={String(target.id)}
                        onValueChange={(value) => onTargetChange(Number(value))}
                        aria-invalid={!!error}
                    />
                    <FieldError>{error}</FieldError>
                </div>
            </CardHeader>
            <CardContent className="flex flex-col gap-6">
                <div className="flex flex-col gap-1">
                    {forTarget ? (
                        <>
                            <BalanceFigure
                                folioId={target.id}
                                preview={preview}
                                showAfter={previewCurrent}
                                currency={currency}
                            />
                            <p className="text-sm text-muted-foreground">
                                Projected{' '}
                                <MoneyText
                                    amount={preview.projectedBefore}
                                    currency={currency}
                                    plain
                                />
                                {previewCurrent && (
                                    <>
                                        {' → '}
                                        <MoneyText
                                            amount={preview.projectedAfter}
                                            currency={currency}
                                            plain
                                        />
                                    </>
                                )}
                            </p>
                        </>
                    ) : (
                        <>
                            <Skeleton className="h-5 w-40" />
                            <Skeleton className="h-5 w-48" />
                        </>
                    )}
                </div>

                <section className="flex flex-col gap-3">
                    <h2 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Incoming
                    </h2>
                    {empty && (
                        <p className="text-sm text-muted-foreground">
                            Nothing is moving yet. Tick charges on the left.
                        </p>
                    )}
                    {groups.map(({ folio, charges }) => (
                        <div key={folio.id} className="flex flex-col gap-1">
                            <h3 className="text-sm font-medium">
                                {folio.label}
                            </h3>
                            <ul className="flex flex-col gap-1">
                                {charges.map((charge) => (
                                    <li
                                        key={charge.lineId}
                                        className="grid grid-cols-[4rem_minmax(0,1fr)_auto_auto] items-center gap-x-2 text-sm"
                                    >
                                        <span className="text-muted-foreground tabular-nums">
                                            {shortDate(charge.date)}
                                        </span>
                                        <span className="min-w-0 truncate">
                                            {charge.description}
                                        </span>
                                        <MoneyText
                                            amount={charge.total}
                                            currency={currency}
                                            plain
                                        />
                                        <Button
                                            type="button"
                                            size="icon-xs"
                                            variant="ghost"
                                            aria-label={`Do not move ${charge.description} of ${shortDate(charge.date)}`}
                                            onClick={() =>
                                                onUntick(charge.lineId)
                                            }
                                        >
                                            <X />
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                    {incoming.length > 0 && (
                        <div className="flex flex-col gap-1">
                            {incoming.map((item, index) => (
                                <p
                                    key={index}
                                    className="flex flex-wrap items-baseline justify-between gap-x-2 text-sm"
                                >
                                    <span className="min-w-0">
                                        <span className="text-muted-foreground">
                                            Scheduled · Room {item.label}:{' '}
                                        </span>
                                        {item.text}
                                    </span>
                                    <MoneyText
                                        amount={item.amount}
                                        currency={currency}
                                        plain
                                    />
                                </p>
                            ))}
                        </div>
                    )}
                    {forTarget &&
                        preview.notes.map((note) => (
                            <Alert key={note}>
                                <Info />
                                <AlertDescription>{note}</AlertDescription>
                            </Alert>
                        ))}
                </section>

                <section className="flex flex-col gap-2">
                    <h2 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Already here
                    </h2>
                    {target.charges.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No charges yet
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-1 text-sm text-muted-foreground">
                            {target.charges.map((charge) => (
                                <li
                                    key={charge.lineId}
                                    className="grid grid-cols-[4rem_minmax(0,1fr)_auto] items-baseline gap-x-2"
                                >
                                    <span className="tabular-nums">
                                        {shortDate(charge.date)}
                                    </span>
                                    <span className="min-w-0 truncate">
                                        {charge.description}
                                    </span>
                                    <MoneyText
                                        amount={charge.total}
                                        currency={currency}
                                        plain
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </CardContent>
        </Card>
    );
}

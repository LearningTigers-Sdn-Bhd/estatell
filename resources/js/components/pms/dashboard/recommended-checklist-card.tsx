import { Link, router } from '@inertiajs/react';
import { ArrowRight, Circle, CircleCheck, Sparkles, X } from 'lucide-react';
import { useState } from 'react';
import { cn } from 'cn';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { store } from '@/routes/settings/recommended-items/dismissals';

type Item = App.Data.Onboarding.RecommendedItemData;
type Checklist = App.Data.Onboarding.RecommendedChecklistData;

function Progress({ checklist }: { checklist: Checklist }) {
    return (
        <div
            role="progressbar"
            aria-label="Recommended steps done"
            aria-valuemin={0}
            aria-valuemax={checklist.total}
            aria-valuenow={checklist.done}
            className="h-1.5 w-full overflow-hidden rounded-full bg-muted"
        >
            <div
                className="h-full rounded-full bg-primary transition-all"
                style={{
                    width: `${(checklist.done / checklist.total) * 100}%`,
                }}
            />
        </div>
    );
}

/**
 * The item's action link and, when the item allows it, Not needed. Support access sees no Not needed, because the
 * server blocks the write. Only the next step gets the solid primary button; the rest are outline.
 */
function ItemActions({
    item,
    canAct,
    primary = true,
    onLeave,
}: {
    item: Item;
    canAct: boolean;
    primary?: boolean;
    onLeave?: () => void;
}) {
    return (
        <div className="ms-auto flex flex-wrap items-center justify-end gap-2">
            <Link
                href={item.actionUrl}
                onClick={onLeave}
                className={buttonVariants({
                    size: 'sm',
                    variant: primary ? 'default' : 'outline',
                })}
            >
                {item.actionLabel}
                <ArrowRight data-icon="inline-end" />
            </Link>
            {item.dismissible && canAct && (
                <Button
                    variant="ghost"
                    size="sm"
                    className="text-muted-foreground"
                    onClick={() =>
                        router.post(
                            store.url(),
                            { item: item.key },
                            { preserveScroll: true },
                        )
                    }
                >
                    <X data-icon="inline-start" />
                    Not needed
                </Button>
            )}
        </div>
    );
}

/** The next two open items by name, then a count of the rest. */
function laterLine(later: Item[]): string {
    const names = later.slice(0, 2).map((item) => item.label);
    const rest = later.length - names.length;

    return `Then: ${[...names, ...(rest > 0 ? [`+${rest}`] : [])].join(', ')}`;
}

/**
 * Setup steps left after going live, under Tonight. The card shows only the next open item and its actions, with a
 * progress bar; See all steps opens every item in a Sheet, so the card never grows.
 */
export function RecommendedChecklistCard({
    checklist,
    canAct,
}: {
    checklist: Checklist;
    /** False during support access, where the server blocks the write. */
    canAct: boolean;
}) {
    const [open, setOpen] = useState(false);
    const openItems = checklist.items.filter((item) => !item.done);
    const doneItems = checklist.items.filter((item) => item.done);
    const [next, ...later] = openItems;

    return (
        <Card size="sm">
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Sparkles
                        aria-hidden
                        className="size-5 text-muted-foreground"
                    />
                    Recommended next
                </CardTitle>
                <CardAction className="text-sm text-muted-foreground tabular-nums">
                    {checklist.done} of {checklist.total}
                </CardAction>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                <Progress checklist={checklist} />
                {next && (
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex min-w-0 flex-1 basis-48 flex-col">
                            <p className="font-medium">{next.label}</p>
                            <p className="text-sm text-muted-foreground">
                                {next.summary}
                            </p>
                        </div>
                        <ItemActions item={next} canAct={canAct} />
                    </div>
                )}
                <div className="flex flex-col items-start gap-1 border-t pt-3">
                    {later.length > 0 && (
                        <p className="text-sm text-muted-foreground">
                            {laterLine(later)}
                        </p>
                    )}
                    <Button
                        variant="ghost"
                        size="sm"
                        className="-ms-2"
                        onClick={() => setOpen(true)}
                    >
                        See all steps
                        <ArrowRight data-icon="inline-end" />
                    </Button>
                </div>
            </CardContent>

            <Sheet open={open} onOpenChange={setOpen}>
                <SheetContent className="data-[side=right]:sm:max-w-lg">
                    <SheetHeader className="gap-1">
                        <SheetTitle>Recommended next</SheetTitle>
                        <SheetDescription className="tabular-nums">
                            {checklist.done} of {checklist.total} done
                        </SheetDescription>
                        <div className="mt-2">
                            <Progress checklist={checklist} />
                        </div>
                    </SheetHeader>
                    <ul className="flex min-h-0 flex-1 flex-col divide-y overflow-y-auto border-t">
                        {[...openItems, ...doneItems].map((item) => (
                            <li
                                key={item.key}
                                className="flex flex-wrap items-center justify-between gap-3 p-4"
                            >
                                <div className="flex min-w-0 flex-1 basis-48 items-start gap-2">
                                    {item.done ? (
                                        <CircleCheck
                                            aria-label="Done"
                                            className="mt-0.5 size-4 shrink-0 text-primary"
                                        />
                                    ) : (
                                        <Circle
                                            aria-hidden
                                            className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                        />
                                    )}
                                    <div className="flex min-w-0 flex-1 flex-col">
                                        <p
                                            className={cn(
                                                'font-medium',
                                                item.done &&
                                                    'text-muted-foreground',
                                            )}
                                        >
                                            {item.label}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {item.summary}
                                        </p>
                                    </div>
                                    {item.done && (
                                        <span className="text-sm text-muted-foreground">
                                            Done
                                        </span>
                                    )}
                                </div>
                                {!item.done && (
                                    <ItemActions
                                        item={item}
                                        canAct={canAct}
                                        primary={item.key === next?.key}
                                        onLeave={() => setOpen(false)}
                                    />
                                )}
                            </li>
                        ))}
                    </ul>
                </SheetContent>
            </Sheet>
        </Card>
    );
}

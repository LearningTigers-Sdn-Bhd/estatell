import { Link, router } from '@inertiajs/react';
import { ArrowRight, Check, ChevronDown, Circle, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { cn } from 'cn';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { store } from '@/routes/settings/recommended-items/dismissals';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';

type Item = App.Data.Onboarding.RecommendedItemData;

/**
 * A compact bento cell with the next open item. Show all reveals the rest; the browser remembers expansion.
 */
export function RecommendedChecklistCard({
    checklist,
    canAct,
    storageKey,
}: {
    checklist: App.Data.Onboarding.RecommendedChecklistData;
    /** False during support access, where the server blocks the write. */
    canAct: boolean;
    storageKey: string;
}) {
    const [expanded, setExpanded] = useState(() => {
        try {
            return (
                typeof window !== 'undefined' &&
                window.localStorage.getItem(storageKey) === '1'
            );
        } catch {
            return false;
        }
    });
    const next = checklist.items.find((item) => !item.done);
    const changeExpanded = (open: boolean) => {
        setExpanded(open);
        try {
            window.localStorage.setItem(storageKey, open ? '1' : '0');
        } catch {
            // The checklist still works when browser storage is unavailable.
        }
    };
    const dismiss = (item: Item) => {
        router.post(store.url(), { item: item.key }, { preserveScroll: true });
    };

    return (
        <Collapsible
            open={expanded}
            onOpenChange={changeExpanded}
            render={<Card />}
        >
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Sparkles
                        aria-hidden
                        className="size-4 text-muted-foreground"
                    />
                    Recommended next
                </CardTitle>
                <CardDescription className="tabular-nums">
                    {checklist.done} of {checklist.total} done
                </CardDescription>
                <CollapsibleTrigger
                    render={<Button variant="ghost" size="sm" />}
                    className="justify-self-start"
                >
                    {expanded ? 'Show less' : 'Show all'}
                    <ChevronDown
                        data-icon="inline-end"
                        className={cn(expanded && 'rotate-180')}
                    />
                </CollapsibleTrigger>
            </CardHeader>

            <CardContent>
                <ul className="flex flex-col gap-4">
                    {(next ? [next] : []).map((item) => (
                        <li
                            key={item.key}
                            className={cn(
                                'flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4',
                                item.done
                                    ? 'max-sm:order-last'
                                    : 'max-sm:order-first',
                            )}
                        >
                            <div className="flex min-w-0 items-start gap-3">
                                {item.done ? (
                                    <Check
                                        aria-label="Done"
                                        className="mt-0.5 size-4 shrink-0 text-success"
                                    />
                                ) : (
                                    <Circle
                                        aria-hidden
                                        className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                    />
                                )}
                                <div className="flex min-w-0 flex-col">
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
                            </div>

                            {!item.done && (
                                <div className="flex flex-col gap-2 sm:flex-row">
                                    <Link
                                        href={item.actionUrl}
                                        className={buttonVariants({
                                            variant: 'outline',
                                            size: 'sm',
                                        })}
                                    >
                                        {item.actionLabel}
                                        <ArrowRight data-icon="inline-end" />
                                    </Link>
                                    {item.dismissible && canAct && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => dismiss(item)}
                                        >
                                            Not needed
                                        </Button>
                                    )}
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
                <CollapsibleContent>
                    <ul className="mt-4 flex flex-col gap-4">
                        {checklist.items
                            .filter((item) => item.key !== next?.key)
                            .map((item) => (
                                <li
                                    key={item.key}
                                    className="flex flex-wrap items-start justify-between gap-3 border-t pt-4"
                                >
                                    <div className="flex min-w-0 flex-1 items-start gap-3">
                                        {item.done ? (
                                            <Check
                                                aria-label="Done"
                                                className="mt-0.5 size-4 shrink-0 text-success"
                                            />
                                        ) : (
                                            <Circle
                                                aria-hidden
                                                className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                            />
                                        )}
                                        <div className="flex min-w-0 flex-col">
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
                                    </div>
                                    {!item.done && (
                                        <div className="flex flex-wrap gap-2">
                                            <Link
                                                href={item.actionUrl}
                                                className={buttonVariants({
                                                    variant: 'outline',
                                                    size: 'sm',
                                                })}
                                            >
                                                {item.actionLabel}
                                                <ArrowRight data-icon="inline-end" />
                                            </Link>
                                            {item.dismissible && canAct && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        dismiss(item)
                                                    }
                                                >
                                                    Not needed
                                                </Button>
                                            )}
                                        </div>
                                    )}
                                </li>
                            ))}
                    </ul>
                </CollapsibleContent>
            </CardContent>
        </Collapsible>
    );
}

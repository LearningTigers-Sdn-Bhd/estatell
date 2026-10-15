import { Link, router } from '@inertiajs/react';
import { ArrowRight, Check, Circle } from 'lucide-react';
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

type Item = App.Data.Onboarding.RecommendedItemData;

/**
 * The Recommended next card. Items keep the server's fixed order; at phone width the open items come first. "Not
 * needed" hides an item for good. Done items show a check and no actions.
 */
export function RecommendedChecklistCard({
    checklist,
    canAct,
}: {
    checklist: App.Data.Onboarding.RecommendedChecklistData;
    /** False during support access, where the server blocks the write. */
    canAct: boolean;
}) {
    const dismiss = (item: Item) => {
        router.post(store.url(), { item: item.key }, { preserveScroll: true });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Recommended next</CardTitle>
                <CardDescription className="tabular-nums">
                    {checklist.done} of {checklist.total} done
                </CardDescription>
            </CardHeader>

            <CardContent>
                <ul className="flex flex-col gap-4">
                    {checklist.items.map((item) => (
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
            </CardContent>
        </Card>
    );
}

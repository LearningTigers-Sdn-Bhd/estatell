import { Head, router } from '@inertiajs/react';
import { ChartPie, Plus } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { PageHeader } from '@/components/pms/page-header';
import { MarketSegmentDialog } from '@/components/pms/settings/market-segment-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { index, update } from '@/routes/settings/market-segments';

type Segment = App.Data.Reservations.MarketSegmentData;

export default function MarketSegmentsIndex({
    segments,
}: {
    segments: Segment[];
}) {
    const [editing, setEditing] = useState<Segment | null | undefined>(
        undefined,
    );
    const [togglingIds, setTogglingIds] = useState<Set<number>>(new Set());

    const toggleActive = (segment: Segment, active: boolean) => {
        const setToggling = (toggling: boolean) =>
            setTogglingIds((current) => {
                const next = new Set(current);

                if (toggling) {
                    next.add(segment.id);
                } else {
                    next.delete(segment.id);
                }

                return next;
            });

        router
            .optimistic<{ segments: Segment[] }>((props) => ({
                segments: props.segments.map((row) =>
                    row.id === segment.id ? { ...row, active } : row,
                ),
            }))
            .put(
                update.url({ marketSegment: segment.id }),
                { name: segment.name, active },
                {
                    preserveScroll: true,
                    onStart: () => setToggling(true),
                    onFinish: () => setToggling(false),
                },
            );
    };

    return (
        <>
            <Head title="Market segments" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Market segments"
                    description="Sort bookings by market, such as Leisure, Corporate or Groups. The booking form shows the field once you add a segment."
                    actions={
                        <Button onClick={() => setEditing(null)}>
                            <Plus data-icon="inline-start" />
                            Add segment
                        </Button>
                    }
                />

                <div className="overflow-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Available</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {segments.length === 0 && (
                                <TableRow className="hover:bg-transparent">
                                    <TableCell colSpan={3}>
                                        <EmptyState
                                            icon={ChartPie}
                                            title="No market segments yet."
                                            description="Add the markets you sell to, such as Leisure, Corporate or Groups."
                                        />
                                    </TableCell>
                                </TableRow>
                            )}
                            {segments.map((segment) => (
                                <TableRow key={segment.id}>
                                    <TableCell>
                                        <Switch
                                            size="sm"
                                            checked={segment.active}
                                            disabled={togglingIds.has(
                                                segment.id,
                                            )}
                                            aria-label={`Make ${segment.name} ${segment.active ? 'unavailable' : 'available'}`}
                                            onCheckedChange={(active) =>
                                                toggleActive(segment, active)
                                            }
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <span className="font-medium">
                                            {segment.name}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex items-center justify-end gap-1">
                                            {!segment.active && (
                                                <Badge variant="secondary">
                                                    Inactive
                                                </Badge>
                                            )}
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setEditing(segment)
                                                }
                                            >
                                                Edit
                                            </Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>

            <MarketSegmentDialog
                open={editing !== undefined}
                onOpenChange={(open) => !open && setEditing(undefined)}
                segment={editing ?? null}
            />
        </>
    );
}

MarketSegmentsIndex.layout = {
    breadcrumbs: [{ title: 'Market segments', href: index() }],
};

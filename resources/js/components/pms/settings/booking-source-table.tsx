import { ArrowDown, ArrowUp } from 'lucide-react';
import { BookingSourceBadge } from '@/components/pms/booking-source-badge';
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
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

type Source = App.Data.Reservations.BookingSourceRowData;

export function BookingSourceTable({
    sources,
    orderable,
    onMove,
    onToggleActive,
    onEdit,
    togglingIds,
}: {
    sources: Source[];
    /** The ids of the active staff rows in list order. */
    orderable: number[];
    onMove: (id: number, direction: -1 | 1) => void;
    onToggleActive: (source: Source, active: boolean) => void;
    onEdit: (source: Source) => void;
    togglingIds: Set<number>;
}) {
    return (
        <div className="overflow-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Available</TableHead>
                        <TableHead>Booking source</TableHead>
                        <TableHead>Type</TableHead>
                        <TableHead>OTA channel</TableHead>
                        <TableHead className="text-right">Used</TableHead>
                        <TableHead>
                            <span className="sr-only">Actions</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {sources.map((source) => {
                        const position = orderable.indexOf(source.id);
                        const name = source.badge.name;

                        return (
                            <TableRow key={source.id}>
                                <TableCell>
                                    {source.isSystem ? (
                                        <Tooltip>
                                            <TooltipTrigger
                                                render={
                                                    <span className="inline-flex" />
                                                }
                                            >
                                                <Switch
                                                    size="sm"
                                                    checked
                                                    disabled
                                                    aria-label={`${name} is always available`}
                                                />
                                            </TooltipTrigger>
                                            <TooltipContent>
                                                Always available
                                            </TooltipContent>
                                        </Tooltip>
                                    ) : (
                                        <Switch
                                            size="sm"
                                            checked={source.isActive}
                                            disabled={togglingIds.has(
                                                source.id,
                                            )}
                                            aria-label={`Make ${name} ${source.isActive ? 'unavailable' : 'available'}`}
                                            onCheckedChange={(active) =>
                                                onToggleActive(source, active)
                                            }
                                        />
                                    )}
                                </TableCell>
                                <TableCell>
                                    <span className="flex items-center gap-2 font-medium">
                                        <BookingSourceBadge
                                            source={source.badge}
                                        />
                                        {name}
                                        {source.isSystem && (
                                            <Badge variant="outline">
                                                System
                                            </Badge>
                                        )}
                                    </span>
                                </TableCell>
                                <TableCell>{source.typeLabel}</TableCell>
                                <TableCell>
                                    {source.otaChannelName ?? (
                                        <span className="text-muted-foreground">
                                            –
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {source.usedCount}
                                </TableCell>
                                <TableCell>
                                    <div className="flex justify-end gap-1">
                                        {position >= 0 && (
                                            <>
                                                <Button
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Move ${name} up`}
                                                    disabled={position === 0}
                                                    onClick={() =>
                                                        onMove(source.id, -1)
                                                    }
                                                >
                                                    <ArrowUp />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Move ${name} down`}
                                                    disabled={
                                                        position ===
                                                        orderable.length - 1
                                                    }
                                                    onClick={() =>
                                                        onMove(source.id, 1)
                                                    }
                                                >
                                                    <ArrowDown />
                                                </Button>
                                            </>
                                        )}
                                        {!source.isSystem &&
                                            !source.isActive && (
                                                <Badge variant="secondary">
                                                    Inactive
                                                </Badge>
                                            )}
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            aria-label={`Edit ${name}`}
                                            onClick={() => onEdit(source)}
                                        >
                                            Edit
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}

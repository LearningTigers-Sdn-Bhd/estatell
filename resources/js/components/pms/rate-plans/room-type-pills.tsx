import { Badge } from '@/components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { splitRoomTypes } from './room-type-overflow';

/**
 * Room type names as pills. Past the limit the rest fold into one "+X" pill whose tooltip lists them.
 */
export function RoomTypePills({ names }: { names: string[] }) {
    if (names.length === 0) {
        return <span className="text-muted-foreground">None yet</span>;
    }

    const { shown, hidden } = splitRoomTypes(names);

    return (
        <div className="flex items-center gap-1">
            {shown.map((name) => (
                <Tooltip key={name}>
                    <TooltipTrigger
                        render={
                            <Badge
                                variant="outline"
                                size="lg"
                                className="max-w-32 min-w-0 justify-start"
                            />
                        }
                    >
                        <span className="truncate">{name}</span>
                    </TooltipTrigger>
                    <TooltipContent>{name}</TooltipContent>
                </Tooltip>
            ))}
            {hidden.length > 0 && (
                <Tooltip>
                    <TooltipTrigger
                        render={
                            <Badge
                                variant="secondary"
                                size="lg"
                                className="tabular-nums"
                                render={<button type="button" />}
                            />
                        }
                        aria-label={`${hidden.length} more room types: ${hidden.join(', ')}`}
                    >
                        +{hidden.length}
                    </TooltipTrigger>
                    <TooltipContent>{hidden.join(', ')}</TooltipContent>
                </Tooltip>
            )}
        </div>
    );
}

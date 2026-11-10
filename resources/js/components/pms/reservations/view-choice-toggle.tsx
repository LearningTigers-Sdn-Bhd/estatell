import { List, ListTree } from 'lucide-react';
import type { ViewChoice } from '@/components/pms/reservations/use-view-choice';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';

/**
 * The All / Grouped toggle of the Guests and Units tabs. `allLabel` names the All button for screen readers, for
 * example "All guests".
 */
export function ViewChoiceToggle({
    view,
    onViewChange,
    allLabel,
}: {
    view: ViewChoice;
    onViewChange: (view: ViewChoice) => void;
    allLabel: string;
}) {
    return (
        <ToggleGroup
            variant="outline"
            spacing={0}
            aria-label="View"
            value={[view]}
            onValueChange={(values) => {
                const next = values[0];

                if (next === 'all' || next === 'grouped') {
                    onViewChange(next);
                }
            }}
        >
            <ToggleGroupItem value="all" aria-label={allLabel} title={allLabel}>
                <List />
                All
            </ToggleGroupItem>
            <ToggleGroupItem
                value="grouped"
                aria-label="Grouped by unit"
                title="Grouped by unit"
            >
                <ListTree />
                Grouped
            </ToggleGroupItem>
        </ToggleGroup>
    );
}

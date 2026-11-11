import { router } from '@inertiajs/react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { note as noteRoute } from '@/routes/boats/trips';

type Row = App.Data.Reports.BoatManifestRow;

/**
 * The Note of one Boat Trips row, edited in place as the Housekeeping remark: it saves when it loses focus or on Enter,
 * and Escape puts the old text back. Without `canEdit` it shows the text only. See docs/24-boats/05-boats-board.md#layout.
 */
export function BoatNoteField({
    row,
    canEdit,
    className,
}: {
    row: Row;
    canEdit: boolean;
    className?: string;
}) {
    if (!canEdit) {
        return row.note ? (
            <span className={cn('text-muted-foreground', className)}>
                {row.note}
            </span>
        ) : null;
    }

    const save = (value: string) => {
        const next = value.trim() === '' ? null : value.trim();

        if (next === (row.note ?? null)) {
            return;
        }

        router.put(
            noteRoute.url({ trip: row.key }),
            { note: next },
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <Input
            key={`${row.key}:${row.note ?? ''}`}
            defaultValue={row.note ?? ''}
            maxLength={255}
            placeholder="e.g. Late ferry"
            aria-label={`Note for ${row.unit} · ${row.lead_guest}`}
            className={className}
            onBlur={(event) => save(event.target.value)}
            onKeyDown={(event) => {
                if (event.key === 'Enter') {
                    event.currentTarget.blur();
                }

                if (event.key === 'Escape') {
                    event.currentTarget.value = row.note ?? '';
                    event.currentTarget.blur();
                }
            }}
        />
    );
}

import { router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from '@/components/ui/toast';

/**
 * The Available switch of a settings list: sends the full row with the new `active` to the update URL at once, shows it
 * optimistically, and disables the switch while it saves. `prop` is the page prop that holds the rows.
 */
export function useAvailableSwitch<Row extends { id: number; active: boolean }>(
    prop: string,
) {
    const [togglingIds, setTogglingIds] = useState<Set<number>>(new Set());

    const setToggling = (id: number, toggling: boolean) =>
        setTogglingIds((current) => {
            const next = new Set(current);

            if (toggling) {
                next.add(id);
            } else {
                next.delete(id);
            }

            return next;
        });

    const toggle = (
        row: Row,
        active: boolean,
        url: string,
        values: Record<string, string>,
    ) =>
        router
            .optimistic<Record<string, Row[]>>((props) => ({
                [prop]: props[prop].map((item) =>
                    item.id === row.id ? { ...item, active } : item,
                ),
            }))
            .put(
                url,
                { ...values, active },
                {
                    preserveScroll: true,
                    onStart: () => setToggling(row.id, true),
                    onFinish: () => setToggling(row.id, false),
                    onError: (errors) =>
                        toast.add({
                            title:
                                Object.values(errors)[0] ??
                                'The change could not be saved.',
                            type: 'error',
                        }),
                },
            );

    return { togglingIds, toggle };
}

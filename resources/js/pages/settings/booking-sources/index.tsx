import { Form, Head, router } from '@inertiajs/react';
import { Plus, Ticket } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { PageHeader } from '@/components/pms/page-header';
import { BookingSourceDialog } from '@/components/pms/settings/booking-source-dialog';
import {
    moveBeside,
    orderableIds,
    orderBookingSources,
} from '@/components/pms/settings/booking-source-order';
import { BookingSourceTable } from '@/components/pms/settings/booking-source-table';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { toast } from '@/components/ui/toast';
import {
    destroy,
    index,
    reorder,
    update,
} from '@/routes/settings/booking-sources';

type Source = App.Data.Reservations.BookingSourceRowData;
type Option = { value: string; label: string };

/** The values of a source, for saving a change that does not come from the Dialog. */
function formValues(source: Source) {
    return {
        name: source.badge.name,
        type: source.badge.type,
        ota_channel_id: source.otaChannelId,
        is_active: source.isActive,
        badge_icon: source.badge.icon,
        badge_letters: source.badge.letters,
        badge_color: source.badge.color,
    };
}

export default function BookingSourcesIndex({
    sources,
    types,
    channels,
    icons,
    colors,
}: {
    sources: Source[];
    types: Option[];
    channels: App.Data.Reservations.BookingSourceChannelOptionData[];
    icons: Option[];
    colors: Option[];
}) {
    const [editing, setEditing] = useState<Source | 'new' | null>(null);
    const [deleting, setDeleting] = useState<Source | null>(null);
    const [togglingIds, setTogglingIds] = useState<Set<number>>(new Set());
    const [query, setQuery] = useState('');
    const shown = useMemo(
        () => orderBookingSources(sources, query),
        [sources, query],
    );
    const orderable = useMemo(() => orderableIds(shown), [shown]);

    const move = (id: number, direction: -1 | 1) => {
        const neighbour = orderable[orderable.indexOf(id) + direction];
        const order =
            neighbour === undefined
                ? null
                : moveBeside(
                      orderableIds(orderBookingSources(sources, '')),
                      id,
                      neighbour,
                  );

        if (order !== null) {
            router.put(reorder.url(), { order }, { preserveScroll: true });
        }
    };

    const toggleActive = (source: Source, active: boolean) => {
        const setToggling = (toggling: boolean) => {
            setTogglingIds((current) => {
                const next = new Set(current);

                if (toggling) {
                    next.add(source.id);
                } else {
                    next.delete(source.id);
                }

                return next;
            });
        };

        router
            .optimistic<{ sources: Source[] }>((props) => ({
                sources: props.sources.map((item) =>
                    item.id === source.id
                        ? { ...item, isActive: active }
                        : item,
                ),
            }))
            .put(
                update.url({ bookingSource: source.id }),
                { ...formValues(source), is_active: active },
                {
                    preserveScroll: true,
                    onStart: () => setToggling(true),
                    onFinish: () => setToggling(false),
                    onError: (errors) =>
                        toast.add({
                            title:
                                Object.values(errors)[0] ??
                                'The change could not be saved.',
                            type: 'error',
                        }),
                },
            );
    };

    const newButton = (
        <Button onClick={() => setEditing('new')}>
            <Plus data-icon="inline-start" />
            New booking source
        </Button>
    );

    return (
        <>
            <Head title="Booking sources" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Booking sources"
                    description="Where your bookings come from. Staff pick one on every booking."
                    actions={newButton}
                />

                {sources.length === 0 ? (
                    <EmptyState
                        icon={Ticket}
                        title="No booking sources yet"
                        description="Add the first place your bookings come from."
                        action={newButton}
                    />
                ) : (
                    <>
                        <Field>
                            <FieldLabel htmlFor="booking-source-search">
                                Search booking sources
                            </FieldLabel>
                            <Input
                                id="booking-source-search"
                                type="search"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                placeholder="e.g. WhatsApp"
                            />
                        </Field>

                        {shown.length > 0 ? (
                            <BookingSourceTable
                                sources={shown}
                                orderable={orderable}
                                onMove={move}
                                onToggleActive={toggleActive}
                                onEdit={setEditing}
                                togglingIds={togglingIds}
                            />
                        ) : (
                            <EmptyState
                                title="No matching booking sources"
                                description="Change the search or add a new booking source."
                            />
                        )}
                    </>
                )}
            </div>

            <BookingSourceDialog
                open={editing !== null}
                onOpenChange={(open) => !open && setEditing(null)}
                source={editing === 'new' ? null : editing}
                types={types}
                channels={channels}
                icons={icons}
                colors={colors}
                onDelete={(source) => {
                    setEditing(null);
                    setDeleting(source);
                }}
            />

            <AlertDialog
                open={deleting !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeleting(null);
                    }
                }}
            >
                <AlertDialogContent>
                    {deleting && (
                        <Form
                            {...destroy.form({ bookingSource: deleting.id })}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setDeleting(null)}
                        >
                            {({ processing }) => (
                                <>
                                    <AlertDialogHeader>
                                        <AlertDialogTitle>
                                            Delete {deleting.badge.name}?
                                        </AlertDialogTitle>
                                        <AlertDialogDescription>
                                            No booking uses it. It is removed
                                            from the list and cannot be
                                            restored.
                                        </AlertDialogDescription>
                                    </AlertDialogHeader>
                                    <AlertDialogFooter>
                                        <AlertDialogCancel>
                                            Cancel
                                        </AlertDialogCancel>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={processing}
                                            focusableWhenDisabled
                                        >
                                            {processing && (
                                                <Spinner data-icon="inline-start" />
                                            )}
                                            Delete booking source
                                        </Button>
                                    </AlertDialogFooter>
                                </>
                            )}
                        </Form>
                    )}
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

BookingSourcesIndex.layout = {
    breadcrumbs: [{ title: 'Booking sources', href: index() }],
};

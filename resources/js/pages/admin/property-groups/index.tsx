import { Head, router } from '@inertiajs/react';
import { Network, Pencil, Plus, X } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { PropertyGroupDialog } from '@/components/pms/property-groups/property-group-dialog';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard } from '@/routes/admin';
import { index } from '@/routes/admin/property-groups';
import {
    destroy as removeProperty,
    store as addProperty,
} from '@/routes/admin/property-groups/properties';

type Group = App.Data.Platform.PropertyGroupData;

function GroupCard({
    group,
    ungrouped,
    onRename,
}: {
    group: Group;
    ungrouped: EnumOption[];
    onRename: () => void;
}) {
    const [picked, setPicked] = useState<string | undefined>(undefined);
    const [busy, setBusy] = useState(false);
    const options = {
        preserveScroll: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>{group.name}</CardTitle>
                <CardDescription>
                    {group.properties.length === 1
                        ? '1 property'
                        : `${group.properties.length} properties`}
                </CardDescription>
                <CardAction>
                    <Button variant="ghost" size="sm" onClick={onRename}>
                        <Pencil data-icon="inline-start" />
                        Rename
                    </Button>
                </CardAction>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                {group.properties.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No properties yet.
                    </p>
                ) : (
                    <ul className="flex flex-col divide-y rounded-md border">
                        {group.properties.map((property) => (
                            <li
                                key={property.code}
                                className="flex items-center justify-between gap-2 px-3 py-2 text-sm"
                            >
                                <span>
                                    <span className="font-medium">
                                        {property.name}
                                    </span>{' '}
                                    <span className="text-muted-foreground">
                                        {property.code}
                                    </span>
                                </span>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label={`Remove ${property.name} from ${group.name}`}
                                    disabled={busy}
                                    onClick={() =>
                                        router.delete(
                                            removeProperty.url({
                                                propertyGroup: group.id,
                                                property: property.code,
                                            }),
                                            options,
                                        )
                                    }
                                >
                                    <X />
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
                <div className="flex flex-wrap items-center gap-2">
                    <EnumSelect
                        aria-label={`Property to add to ${group.name}`}
                        options={ungrouped}
                        value={picked}
                        onValueChange={setPicked}
                        placeholder="Choose a property"
                        className="min-w-64 flex-1"
                        disabled={ungrouped.length === 0}
                    />
                    <Button
                        variant="outline"
                        disabled={picked === undefined || busy}
                        focusableWhenDisabled
                        onClick={() =>
                            picked !== undefined &&
                            router.post(
                                addProperty.url({ propertyGroup: group.id }),
                                { property: picked },
                                {
                                    ...options,
                                    onSuccess: () => setPicked(undefined),
                                },
                            )
                        }
                    >
                        <Plus data-icon="inline-start" />
                        Add property
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

export default function PropertyGroupsIndex({
    groups,
    ungrouped,
}: {
    groups: Group[];
    ungrouped: EnumOption[];
}) {
    const [dialog, setDialog] = useState<{ group: Group | null } | null>(null);

    return (
        <>
            <Head title="Property groups" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Property groups"
                    description="Properties run by one business. A manager of the group can give staff access to the group's other properties in Settings › Staff. Nobody gets access by being in a group alone."
                    actions={
                        <Button onClick={() => setDialog({ group: null })}>
                            <Plus data-icon="inline-start" />
                            New group
                        </Button>
                    }
                />

                {groups.length === 0 ? (
                    <EmptyState
                        icon={Network}
                        title="No property groups yet"
                        description="Create a group for a business that runs more than one property, then add its properties."
                    />
                ) : (
                    <div className="grid gap-4 lg:grid-cols-2">
                        {groups.map((group) => (
                            <GroupCard
                                key={group.id}
                                group={group}
                                ungrouped={ungrouped}
                                onRename={() => setDialog({ group })}
                            />
                        ))}
                    </div>
                )}
            </div>

            <PropertyGroupDialog
                open={dialog !== null}
                onOpenChange={(open) => !open && setDialog(null)}
                group={dialog?.group ?? null}
            />
        </>
    );
}

PropertyGroupsIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Property groups', href: index() },
    ],
};

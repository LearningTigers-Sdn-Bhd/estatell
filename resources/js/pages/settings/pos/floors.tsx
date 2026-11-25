import { router, useForm } from '@inertiajs/react';
import { LayoutGrid, Plus } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    AddTablesForm,
    FloorCanvas,
    RenameFloorForm,
    TableFormSheet,
} from '@/components/pms/pos/pos-floor-editor';
import type {
    EditorFloor,
    EditorTable,
} from '@/components/pms/pos/pos-floor-editor';
import {
    PosSettingsShell,
    posSettingsBreadcrumbs,
} from '@/components/pms/pos/pos-settings-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    index as floorsIndex,
    store as storeFloor,
} from '@/routes/settings/pos/floors';

type Props = {
    outlets: EnumOption[];
    outlet: { id: number; name: string } | null;
    floors: EditorFloor[];
    shapes: EnumOption[];
};

export default function PosFloors({ outlets, outlet, floors, shapes }: Props) {
    const newFloor = useForm({ name: '' });
    const [editingTable, setEditingTable] = useState<{
        floorId: number;
        table: EditorTable | null;
    } | null>(null);

    return (
        <PosSettingsShell activeTab="floors">
            <div className="flex flex-col gap-6">
                {outlets.length > 1 && (
                    <EnumSelect
                        name="outlet"
                        options={outlets}
                        value={String(outlet?.id ?? '')}
                        onValueChange={(value) =>
                            router.get(
                                floorsIndex.url(undefined, {
                                    query: { outlet: value },
                                }),
                            )
                        }
                        aria-label="Outlet"
                    />
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Floors</CardTitle>
                        <CardDescription>
                            Dining areas and tables for a pay-later outlet. Drag
                            or use arrow keys to position tables, and click any
                            table to edit its shape, seats, or label.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form
                            className="flex flex-wrap items-end gap-3"
                            onSubmit={(event) => {
                                event.preventDefault();

                                if (outlet !== null) {
                                    newFloor.post(
                                        storeFloor.url({ outlet: outlet.id }),
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => newFloor.reset(),
                                        },
                                    );
                                }
                            }}
                        >
                            <Field
                                className="w-64"
                                data-invalid={!!newFloor.errors.name}
                            >
                                <FieldLabel htmlFor="new-floor-name">
                                    New floor
                                </FieldLabel>
                                <Input
                                    id="new-floor-name"
                                    placeholder="Indoor"
                                    value={newFloor.data.name}
                                    onChange={(event) =>
                                        newFloor.setData(
                                            'name',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={!!newFloor.errors.name}
                                />
                                <FieldError>{newFloor.errors.name}</FieldError>
                            </Field>
                            <Button
                                type="submit"
                                disabled={
                                    outlet === null || newFloor.processing
                                }
                                focusableWhenDisabled
                            >
                                {newFloor.processing ? (
                                    <Spinner data-icon="inline-start" />
                                ) : (
                                    <Plus data-icon="inline-start" />
                                )}
                                Add floor
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                {floors.length === 0 ? (
                    <Card>
                        <CardContent className="pt-6">
                            <EmptyState
                                icon={LayoutGrid}
                                title="No floors yet"
                                description="Add your first dining area above (for example Main Dining, Terrace, or Bar) to start laying out tables."
                            />
                        </CardContent>
                    </Card>
                ) : (
                    floors.map((floor) => (
                        <Card key={floor.id}>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    {floor.name}
                                    {!floor.active && (
                                        <Badge variant="outline">
                                            Inactive
                                        </Badge>
                                    )}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-4">
                                <RenameFloorForm floor={floor} />
                                <AddTablesForm
                                    floor={floor}
                                    onAddSingle={() =>
                                        setEditingTable({
                                            floorId: floor.id,
                                            table: null,
                                        })
                                    }
                                />
                                <FloorCanvas
                                    floor={floor}
                                    onEditTable={(table) =>
                                        setEditingTable({
                                            floorId: floor.id,
                                            table,
                                        })
                                    }
                                    onAddSingleTable={() =>
                                        setEditingTable({
                                            floorId: floor.id,
                                            table: null,
                                        })
                                    }
                                />
                            </CardContent>
                        </Card>
                    ))
                )}
            </div>

            {editingTable !== null && (
                <TableFormSheet
                    key={editingTable.table?.id ?? `new-${editingTable.floorId}`}
                    floorId={editingTable.floorId}
                    table={editingTable.table}
                    shapes={shapes}
                    onClose={() => setEditingTable(null)}
                />
            )}
        </PosSettingsShell>
    );
}

PosFloors.layout = {
    breadcrumbs: posSettingsBreadcrumbs('floors'),
};

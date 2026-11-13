import { router } from '@inertiajs/react';
import { ListChecks } from 'lucide-react';
import { Fragment, useState, type ReactNode } from 'react';
import {
    AmenityGroupRow,
    AmenityViewToggle,
} from '@/components/pms/amenities/amenity-table-parts';
import { useAmenityTable } from '@/components/pms/amenities/use-amenity-table';
import { EmptyState } from '@/components/pms/empty-state';
import { FilterBar } from '@/components/pms/filter-bar';
import { PaginationFooter } from '@/components/pms/pagination-footer';
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
import { cn } from '@/lib/utils';

type Picker = App.Data.BookingEngine.AmenityPickerData;
type Amenity = App.Data.BookingEngine.AmenityData;

function PickerRow({
    amenity,
    on,
    showCategory,
    saveUrl,
    readOnly,
    action,
}: {
    amenity: Amenity;
    on: boolean;
    showCategory: boolean;
    saveUrl: string;
    readOnly: boolean;
    action?: ReactNode;
}) {
    const [saving, setSaving] = useState(false);

    /** The switch flips at once and follows the server's answer. It is async so a quick next switch does not cancel this save. */
    function save(active: boolean) {
        router
            .optimistic<{ amenities: Picker }>((props) => ({
                amenities: {
                    ...props.amenities,
                    selectedIds: active
                        ? [...props.amenities.selectedIds, amenity.id]
                        : props.amenities.selectedIds.filter(
                              (id) => id !== amenity.id,
                          ),
                },
            }))
            .put(
                saveUrl,
                { amenity_id: amenity.id, active },
                {
                    async: true,
                    preserveScroll: true,
                    preserveState: true,
                    onStart: () => setSaving(true),
                    onFinish: () => setSaving(false),
                },
            );
    }

    return (
        <TableRow>
            <TableCell className="w-24">
                <Switch
                    size="sm"
                    checked={on}
                    disabled={readOnly || saving}
                    aria-label={`Turn ${amenity.name} ${on ? 'off' : 'on'}`}
                    onCheckedChange={save}
                />
            </TableCell>
            <TableCell className="font-medium">
                <span className="flex items-center gap-2">
                    {amenity.name}
                    {!amenity.active && (
                        <Badge variant="secondary">Retired</Badge>
                    )}
                </span>
            </TableCell>
            {showCategory && (
                <TableCell className="text-muted-foreground">
                    {amenity.category}
                </TableCell>
            )}
            {action !== undefined && (
                <TableCell className="text-end">{action}</TableCell>
            )}
        </TableRow>
    );
}

/**
 * The amenities a property or room type can have, as a table with one switch for each. Each switch saves at once.
 * A retired amenity shows only while it is on, so it can be turned off.
 */
export function AmenityPicker({
    picker,
    saveUrl,
    description,
    readOnly,
    page,
    listUrl,
    headerClassName,
    rowAction,
}: {
    picker: Picker;
    saveUrl: string;
    description: string;
    /** Extra classes for the description, for example the heading inset inside a Workspace panel. */
    headerClassName?: string;
    readOnly: boolean;
    page: number;
    listUrl: string;
    /** An extra cell at the end of each row, for example a button that opens the amenity's details. */
    rowAction?: (amenity: Amenity, on: boolean) => ReactNode;
}) {
    const table = useAmenityTable(picker.amenities, page, listUrl);
    const isOn = (amenity: Amenity) => picker.selectedIds.includes(amenity.id);
    const columnCount = (table.view === 'list' ? 3 : 2) + (rowAction ? 1 : 0);

    if (picker.amenities.length === 0) {
        return (
            <EmptyState
                icon={ListChecks}
                title="No amenities yet"
                description="Support adds the amenities you can pick from."
            />
        );
    }

    return (
        <div className="flex flex-col gap-4">
            <p className={cn('text-sm text-muted-foreground', headerClassName)}>
                {description}
            </p>

            <FilterBar
                search={table.search}
                onSearchChange={table.setSearch}
                searchPlaceholder="Search by name or category"
            >
                <AmenityViewToggle
                    view={table.view}
                    onChange={table.changeView}
                />
            </FilterBar>

            <div className="overflow-hidden rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Available</TableHead>
                            <TableHead>Amenity</TableHead>
                            {table.view === 'list' && (
                                <TableHead>Category</TableHead>
                            )}
                            {rowAction && (
                                <TableHead className="text-end">
                                    Details
                                </TableHead>
                            )}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {table.view === 'list'
                            ? table.listRows.map((amenity) => (
                                  <PickerRow
                                      key={amenity.id}
                                      amenity={amenity}
                                      on={isOn(amenity)}
                                      showCategory
                                      saveUrl={saveUrl}
                                      readOnly={readOnly}
                                      action={
                                          rowAction &&
                                          (rowAction(amenity, isOn(amenity)) ??
                                              null)
                                      }
                                  />
                              ))
                            : table.groups.map((group) => (
                                  <Fragment key={group.id}>
                                      <AmenityGroupRow
                                          name={group.name}
                                          count={`${group.rows.filter(isOn).length} of ${group.rows.length}`}
                                          open={table.isGroupOpen(group.id)}
                                          disabled={table.term !== ''}
                                          colSpan={columnCount}
                                          onToggle={() =>
                                              table.toggleGroup(group.id)
                                          }
                                      />
                                      {table.isGroupOpen(group.id) &&
                                          group.rows.map((amenity) => (
                                              <PickerRow
                                                  key={amenity.id}
                                                  amenity={amenity}
                                                  on={isOn(amenity)}
                                                  showCategory={false}
                                                  saveUrl={saveUrl}
                                                  readOnly={readOnly}
                                                  action={
                                                      rowAction &&
                                                      (rowAction(
                                                          amenity,
                                                          isOn(amenity),
                                                      ) ??
                                                          null)
                                                  }
                                              />
                                          ))}
                                  </Fragment>
                              ))}
                        {table.visible.length === 0 && (
                            <TableRow className="hover:bg-transparent">
                                <TableCell colSpan={columnCount}>
                                    <EmptyState
                                        icon={ListChecks}
                                        title="No amenities match your search"
                                        action={
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    table.setSearch('')
                                                }
                                            >
                                                Clear search
                                            </Button>
                                        }
                                    />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </div>

            {table.view === 'list' && table.term === '' && (
                <PaginationFooter pagination={table.pagination} />
            )}
        </div>
    );
}

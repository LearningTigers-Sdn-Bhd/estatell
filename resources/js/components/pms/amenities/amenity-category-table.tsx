import { Form, router } from '@inertiajs/react';
import { ChevronDown, ChevronUp, Tags } from 'lucide-react';
import { Fragment, useState } from 'react';
import { AmenityCategoryDialog } from '@/components/pms/amenities/amenity-category-dialog';
import { EmptyState } from '@/components/pms/empty-state';
import type { EnumOption } from '@/components/pms/enum-select';
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
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { destroy, reorder } from '@/routes/admin/amenities/categories';

type Category = App.Data.BookingEngine.AmenityCategoryData;

/**
 * Swap a category with its neighbour of the same type and send the whole order. The server checks that the ids are the full list.
 */
function moveCategory(
    categories: Category[],
    group: Category[],
    position: number,
    step: -1 | 1,
) {
    const ids = categories.map((category) => category.id);
    const from = ids.indexOf(group[position].id);
    const to = ids.indexOf(group[position + step].id);

    [ids[from], ids[to]] = [ids[to], ids[from]];

    router.put(
        reorder.url(),
        { order: ids },
        { preserveScroll: true, preserveState: true },
    );
}

/**
 * The Categories tab: every category under a row for its type, with its order, name and delete. Only an empty category can be deleted.
 */
export function AmenityCategoryTable({
    categories,
    typeOptions,
}: {
    categories: Category[];
    typeOptions: EnumOption[];
}) {
    const [editing, setEditing] = useState<Category | null>(null);
    const [deleting, setDeleting] = useState<Category | null>(null);

    return (
        <>
            <div className="overflow-hidden rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Category</TableHead>
                            <TableHead>Amenities</TableHead>
                            <TableHead>
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {typeOptions.map((option) => {
                            const group = categories.filter(
                                (category) => category.type === option.value,
                            );

                            return (
                                group.length > 0 && (
                                    <Fragment key={option.value}>
                                        <TableRow className="bg-muted/50 hover:bg-muted/50">
                                            <TableCell
                                                colSpan={3}
                                                className="font-medium"
                                            >
                                                {option.label}
                                            </TableCell>
                                        </TableRow>
                                        {group.map((category, position) => (
                                            <TableRow key={category.id}>
                                                <TableCell className="font-medium">
                                                    {category.name}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground tabular-nums">
                                                    {category.amenitiesCount}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex items-center justify-end gap-1">
                                                        <Button
                                                            variant="ghost"
                                                            size="icon-sm"
                                                            aria-label={`Move ${category.name} up`}
                                                            disabled={
                                                                position === 0
                                                            }
                                                            onClick={() =>
                                                                moveCategory(
                                                                    categories,
                                                                    group,
                                                                    position,
                                                                    -1,
                                                                )
                                                            }
                                                        >
                                                            <ChevronUp />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon-sm"
                                                            aria-label={`Move ${category.name} down`}
                                                            disabled={
                                                                position ===
                                                                group.length - 1
                                                            }
                                                            onClick={() =>
                                                                moveCategory(
                                                                    categories,
                                                                    group,
                                                                    position,
                                                                    1,
                                                                )
                                                            }
                                                        >
                                                            <ChevronDown />
                                                        </Button>
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() =>
                                                                setEditing(
                                                                    category,
                                                                )
                                                            }
                                                        >
                                                            Edit
                                                        </Button>
                                                        {category.amenitiesCount ===
                                                            0 && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() =>
                                                                    setDeleting(
                                                                        category,
                                                                    )
                                                                }
                                                            >
                                                                Delete
                                                            </Button>
                                                        )}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </Fragment>
                                )
                            );
                        })}
                        {categories.length === 0 && (
                            <TableRow className="hover:bg-transparent">
                                <TableCell colSpan={3}>
                                    <EmptyState
                                        icon={Tags}
                                        title="No categories yet"
                                        description="Add one so amenities have a group."
                                    />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </div>

            {editing && (
                <AmenityCategoryDialog
                    category={editing}
                    typeOptions={typeOptions}
                    open
                    onOpenChange={(open) => !open && setEditing(null)}
                />
            )}

            <AlertDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Delete {deleting?.name}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            This category has no amenities. You can add it again
                            later.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    {deleting && (
                        <Form
                            {...destroy.form(deleting.id)}
                            options={{
                                preserveScroll: true,
                                preserveState: true,
                            }}
                            onSuccess={() => setDeleting(null)}
                        >
                            {({ processing }) => (
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
                                        Delete category
                                    </Button>
                                </AlertDialogFooter>
                            )}
                        </Form>
                    )}
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

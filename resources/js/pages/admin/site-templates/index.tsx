import { Head, router } from '@inertiajs/react';
import {
    Archive,
    CircleCheck,
    Ellipsis,
    ExternalLink,
    LayoutTemplate,
    Pencil,
    PencilLine,
    Plus,
    Wrench,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import type { EnumOption } from '@/components/pms/enum-select';
import { FilterBar } from '@/components/pms/filter-bar';
import { PageHeader } from '@/components/pms/page-header';
import { SiteTemplateSheet } from '@/components/pms/site-templates/site-template-sheet';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { dashboard } from '@/routes/admin';
import { index } from '@/routes/admin/site-templates';
import { update as updateStatus } from '@/routes/admin/site-templates/status';

type Template = App.Data.BookingEngine.SiteTemplateData;

/** The order of the Availability buttons. */
const statusOrder = ['available', 'retired', 'draft'];

const typeIcons: Record<string, LucideIcon> = {
    standard: LayoutTemplate,
    custom: Wrench,
};

const statusIcons: Record<string, LucideIcon> = {
    available: CircleCheck,
    retired: Archive,
    draft: PencilLine,
};

function TypeLabel({ template }: { template: Template }) {
    const Icon = typeIcons[template.type];

    return (
        <span className="flex items-center gap-1.5">
            {Icon && <Icon className="size-4 text-muted-foreground" />}
            {template.typeLabel}
        </span>
    );
}

export default function SiteTemplatesIndex({
    templates,
    typeOptions,
    statusOptions,
    properties,
}: {
    templates: Template[];
    typeOptions: EnumOption[];
    statusOptions: EnumOption[];
    properties: EnumOption[];
}) {
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<Template | null>(null);
    const [search, setSearch] = useState('');
    const [moving, setMoving] = useState<number | null>(null);
    const orderedStatuses = useMemo(
        () =>
            [...statusOptions].sort(
                (first, second) =>
                    statusOrder.indexOf(first.value) -
                    statusOrder.indexOf(second.value),
            ),
        [statusOptions],
    );

    const visible = useMemo(() => {
        const term = search.trim().toLowerCase();

        return templates.filter(
            (template) =>
                term === '' ||
                template.name.toLowerCase().includes(term) ||
                template.key.toLowerCase().includes(term),
        );
    }, [templates, search]);

    function move(template: Template, status: string) {
        router.put(
            updateStatus.url(template.id),
            { status },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setMoving(template.id),
                onFinish: () => setMoving(null),
            },
        );
    }

    return (
        <>
            <Head title="Booking website templates" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Booking website templates"
                    description="The designs owners can pick. Add the code to the microsite repo first, check the draft, then make it available."
                    actions={
                        <Button onClick={() => setAdding(true)}>
                            <Plus data-icon="inline-start" />
                            Add template
                        </Button>
                    }
                />

                <FilterBar
                    search={search}
                    onSearchChange={setSearch}
                    searchPlaceholder="Search by name or key"
                />

                <div className="overflow-hidden rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-px">
                                    Availability
                                </TableHead>
                                <TableHead>Template</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead className="text-right">
                                    Slots
                                </TableHead>
                                <TableHead className="text-right">
                                    Sites
                                </TableHead>
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {visible.map((template) => (
                                <TableRow key={template.id}>
                                    <TableCell>
                                        <ToggleGroup
                                            variant="outline"
                                            size="sm"
                                            spacing={0}
                                            value={[template.status.value]}
                                            disabled={moving === template.id}
                                            onValueChange={(value) =>
                                                value[0] &&
                                                value[0] !==
                                                    template.status.value &&
                                                move(template, value[0])
                                            }
                                            aria-label={`Availability of ${template.name}`}
                                        >
                                            {orderedStatuses.map((option) => {
                                                const Icon =
                                                    statusIcons[option.value];

                                                return (
                                                    <ToggleGroupItem
                                                        key={option.value}
                                                        value={option.value}
                                                        disabled={
                                                            option.value !==
                                                                template.status
                                                                    .value &&
                                                            !template.transitions.some(
                                                                (transition) =>
                                                                    transition.value ===
                                                                    option.value,
                                                            )
                                                        }
                                                    >
                                                        {Icon && (
                                                            <Icon data-icon="inline-start" />
                                                        )}
                                                        {option.label}
                                                    </ToggleGroupItem>
                                                );
                                            })}
                                        </ToggleGroup>
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex flex-col">
                                            <span className="font-medium">
                                                {template.name}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {template.key}
                                            </span>
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex flex-col">
                                            <TypeLabel template={template} />
                                            {template.propertyLabel && (
                                                <span className="text-xs text-muted-foreground">
                                                    {template.propertyLabel}
                                                </span>
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {template.slots.length}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {template.sitesCount}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex items-center justify-end gap-1">
                                            {template.previewUrl && (
                                                <a
                                                    href={template.previewUrl}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    aria-label={`Preview ${template.name}`}
                                                    className="inline-flex size-8 items-center justify-center rounded-md hover:bg-muted"
                                                >
                                                    <ExternalLink className="size-4" />
                                                </a>
                                            )}
                                            <DropdownMenu>
                                                <DropdownMenuTrigger
                                                    render={
                                                        <Button
                                                            variant="ghost"
                                                            size="icon-sm"
                                                            aria-label={`Actions for ${template.name}`}
                                                        />
                                                    }
                                                >
                                                    <Ellipsis />
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            setEditing(template)
                                                        }
                                                    >
                                                        <Pencil />
                                                        Edit
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                            {visible.length === 0 && (
                                <TableRow className="hover:bg-transparent">
                                    <TableCell colSpan={6}>
                                        <EmptyState
                                            icon={LayoutTemplate}
                                            title={
                                                search === ''
                                                    ? 'No templates yet'
                                                    : 'No templates match your search'
                                            }
                                            description={
                                                search === ''
                                                    ? 'Add the first one after its code is in the microsite repo.'
                                                    : undefined
                                            }
                                        />
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>

            <SiteTemplateSheet
                open={adding}
                onOpenChange={setAdding}
                template={null}
                typeOptions={typeOptions}
                properties={properties}
            />
            <SiteTemplateSheet
                open={editing !== null}
                onOpenChange={(open) => !open && setEditing(null)}
                template={editing}
                typeOptions={typeOptions}
                properties={properties}
            />
        </>
    );
}

SiteTemplatesIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Website templates', href: index() },
    ],
};

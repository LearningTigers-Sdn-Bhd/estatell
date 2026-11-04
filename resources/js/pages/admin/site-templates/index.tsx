import { Head, router } from '@inertiajs/react';
import { ExternalLink, LayoutTemplate, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import type { EnumOption } from '@/components/pms/enum-select';
import { FilterBar } from '@/components/pms/filter-bar';
import { PageHeader } from '@/components/pms/page-header';
import { SiteTemplateSheet } from '@/components/pms/site-templates/site-template-sheet';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dashboard } from '@/routes/admin';
import { index } from '@/routes/admin/site-templates';
import { update as updateStatus } from '@/routes/admin/site-templates/status';

type Template = App.Data.BookingEngine.SiteTemplateData;

export default function SiteTemplatesIndex({
    templates,
    typeOptions,
    properties,
}: {
    templates: Template[];
    typeOptions: EnumOption[];
    properties: EnumOption[];
}) {
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<Template | null>(null);
    const [search, setSearch] = useState('');
    const [moving, setMoving] = useState<number | null>(null);

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
                                <TableHead>Template</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Status</TableHead>
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
                                            <span>{template.typeLabel}</span>
                                            {template.propertyLabel && (
                                                <span className="text-xs text-muted-foreground">
                                                    {template.propertyLabel}
                                                </span>
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge status={template.status} />
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
                                            {template.transitions.map(
                                                (transition) => (
                                                    <Button
                                                        key={transition.value}
                                                        variant="outline"
                                                        size="sm"
                                                        disabled={
                                                            moving ===
                                                            template.id
                                                        }
                                                        focusableWhenDisabled
                                                        onClick={() =>
                                                            move(
                                                                template,
                                                                transition.value,
                                                            )
                                                        }
                                                    >
                                                        {transition.label}
                                                    </Button>
                                                ),
                                            )}
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    setEditing(template)
                                                }
                                            >
                                                Edit
                                            </Button>
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

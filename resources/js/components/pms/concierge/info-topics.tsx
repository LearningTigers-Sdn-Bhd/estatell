import { Form, router, useForm } from '@inertiajs/react';
import { Ellipsis, Info, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { keepPage } from '@/components/pms/concierge/content-fields';
import { PanelSection } from '@/components/pms/concierge/guide-layout';
import { EmptyState } from '@/components/pms/empty-state';
import { FormOverlay } from '@/components/pms/form-overlay';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { destroy, store, update } from '@/routes/settings/concierge/topics';
import { update as active } from '@/routes/settings/concierge/topics/active';

type Topic = App.Data.Concierge.ConciergeTopicData;

/**
 * Extra topics the property tells guests about, each under its own title (laundry, parking rules, nearby clinic).
 * A table with the Hide/Show switch on the left; Add topic and the row menu's Edit open a dialog, Delete asks first. A topic
 * that is off is hidden from guests but still counts toward `limit`.
 */
export function InfoTopics({
    topics,
    limit,
}: {
    topics: Topic[];
    limit: number;
}) {
    /** The open topic dialog: `topic` is null when adding. */
    const [dialog, setDialog] = useState<{ topic: Topic | null } | null>(null);
    const [deleting, setDeleting] = useState<Topic | null>(null);
    const full = topics.length >= limit;

    return (
        <PanelSection
            title="Interesting topics"
            description="Share things guests may like to know, such as laundry, parking rules or a nearby clinic. They appear under The property in the guide."
            action={
                <div className="flex items-center gap-2">
                    <Badge variant="outline">
                        {topics.length} of {limit}
                    </Badge>
                    <Button
                        type="button"
                        disabled={full}
                        focusableWhenDisabled
                        title={full ? `Up to ${limit} topics` : undefined}
                        onClick={() => setDialog({ topic: null })}
                    >
                        <Plus data-icon="inline-start" />
                        Add topic
                    </Button>
                </div>
            }
        >
            <div className="overflow-x-auto rounded-xl border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-24">Hide/Show</TableHead>
                            <TableHead className="w-48">Topic</TableHead>
                            <TableHead>Description</TableHead>
                            <TableHead className="w-12">
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {topics.length === 0 && (
                            <TableRow className="hover:bg-transparent">
                                <TableCell colSpan={4}>
                                    <EmptyState
                                        icon={Info}
                                        title="No interesting topics"
                                        description="Use Add topic for anything guests may like to know."
                                    />
                                </TableCell>
                            </TableRow>
                        )}
                        {topics.map((topic) => (
                            <TableRow key={topic.id}>
                                <TableCell>
                                    <Switch
                                        size="sm"
                                        checked={topic.active}
                                        aria-label={`${topic.active ? 'Hide' : 'Show'} ${topic.title} ${topic.active ? 'from' : 'to'} guests`}
                                        onCheckedChange={(next) =>
                                            router.put(
                                                active.url({
                                                    infoTopic: topic.id,
                                                }),
                                                { active: next },
                                                keepPage,
                                            )
                                        }
                                    />
                                </TableCell>
                                <TableCell className="font-medium whitespace-normal">
                                    {topic.title}
                                </TableCell>
                                <TableCell className="text-pretty whitespace-pre-wrap text-muted-foreground">
                                    {topic.body}
                                </TableCell>
                                <TableCell>
                                    <div className="flex justify-end">
                                        <DropdownMenu>
                                            <DropdownMenuTrigger
                                                render={
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        aria-label={`Actions for ${topic.title}`}
                                                    />
                                                }
                                            >
                                                <Ellipsis />
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuItem
                                                    onClick={() =>
                                                        setDialog({ topic })
                                                    }
                                                >
                                                    <Pencil />
                                                    Edit
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    variant="destructive"
                                                    onClick={() =>
                                                        setDeleting(topic)
                                                    }
                                                >
                                                    <Trash2 />
                                                    Delete
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
            {dialog && (
                <TopicDialog
                    key={dialog.topic?.id ?? 'new'}
                    topic={dialog.topic}
                    onClose={() => setDialog(null)}
                />
            )}
            <DeleteTopicDialog
                topic={deleting}
                onOpenChange={(open) => !open && setDeleting(null)}
            />
        </PanelSection>
    );
}

/** The dialog to add a topic (`topic` null) or change one's title and details. */
function TopicDialog({
    topic,
    onClose,
}: {
    topic: Topic | null;
    onClose: () => void;
}) {
    const form = useForm({
        title: topic?.title ?? '',
        body: topic?.body ?? '',
    });
    const { data, errors } = form;
    const options = { ...keepPage, onSuccess: onClose };

    return (
        <FormOverlay
            variant="dialog"
            open
            onOpenChange={(open) => !open && onClose()}
            title={topic ? 'Edit topic' : 'Add a topic'}
            description="Guests see the title and details under The property in the guide."
            errors={errors}
            onSubmit={() =>
                topic
                    ? form.put(update.url({ infoTopic: topic.id }), options)
                    : form.post(store.url(), options)
            }
            submitLabel={topic ? 'Save topic' : 'Add topic'}
            processing={form.processing}
        >
            <Field data-invalid={!!errors.title}>
                <FieldLabel htmlFor="topic_title">Title</FieldLabel>
                <Input
                    id="topic_title"
                    placeholder="e.g. Laundry"
                    value={data.title}
                    maxLength={100}
                    onChange={(event) =>
                        form.setData('title', event.target.value)
                    }
                    aria-invalid={!!errors.title}
                />
                <FieldError>{errors.title}</FieldError>
            </Field>
            <Field data-invalid={!!errors.body}>
                <FieldLabel htmlFor="topic_body">Details</FieldLabel>
                <Textarea
                    id="topic_body"
                    placeholder="e.g. Self-service laundry on level 2, open 8 am to 10 pm. Detergent is sold at the front desk."
                    value={data.body}
                    rows={4}
                    maxLength={2000}
                    onChange={(event) =>
                        form.setData('body', event.target.value)
                    }
                    aria-invalid={!!errors.body}
                />
                <FieldError>{errors.body}</FieldError>
            </Field>
        </FormOverlay>
    );
}

/** Confirms deleting a topic. It cannot be undone. */
function DeleteTopicDialog({
    topic,
    onOpenChange,
}: {
    topic: Topic | null;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <AlertDialog open={topic !== null} onOpenChange={onOpenChange}>
            {topic && (
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Delete {topic.title}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Guests will no longer see this topic. This cannot be
                            undone. To hide it for a while, switch it off
                            instead.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <Form
                        {...destroy.form({ infoTopic: topic.id })}
                        options={keepPage}
                        onSuccess={() => onOpenChange(false)}
                        disableWhileProcessing
                    >
                        {({ processing }) => (
                            <AlertDialogFooter>
                                <AlertDialogCancel disabled={processing}>
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
                                    Delete topic
                                </Button>
                            </AlertDialogFooter>
                        )}
                    </Form>
                </AlertDialogContent>
            )}
        </AlertDialog>
    );
}

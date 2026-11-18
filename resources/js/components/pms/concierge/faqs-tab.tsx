import { Form, router, useForm } from '@inertiajs/react';
import { CircleHelp, Ellipsis, Pencil, Plus, Trash2 } from 'lucide-react';
import { Fragment, useState } from 'react';
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
    Combobox,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
} from '@/components/ui/combobox';
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
import { destroy, store, update } from '@/routes/settings/concierge/faqs';
import { update as active } from '@/routes/settings/concierge/faqs/active';

type Faq = App.Data.Concierge.ConciergeFaqData;

/** The topics in the order the guest guide shows them: by the first question of each. `''` is the group with no topic. */
function topicsInGuideOrder(faqs: Faq[]): string[] {
    return [...new Set(faqs.map((faq) => faq.topic ?? ''))];
}

/**
 * The questions guests ask, grouped under their topic like the guest guide. A table with the Hide/Show switch on the left;
 * Add question and the row menu's Edit open a dialog, Delete asks first. A question that is off is hidden from guests but
 * still counts toward `limit`.
 */
export function FaqTable({ faqs, limit }: { faqs: Faq[]; limit: number }) {
    /** The open question dialog: `faq` is null when adding. */
    const [dialog, setDialog] = useState<{ faq: Faq | null } | null>(null);
    const [deleting, setDeleting] = useState<Faq | null>(null);
    const full = faqs.length >= limit;
    const topics = topicsInGuideOrder(faqs);

    return (
        <PanelSection
            title="Questions guests ask"
            description="Guests see these answers in the property guide, grouped by topic, in this order."
            action={
                <div className="flex items-center gap-2">
                    <Badge variant="outline">
                        {faqs.length} of {limit}
                    </Badge>
                    <Button
                        type="button"
                        disabled={full}
                        focusableWhenDisabled
                        title={full ? `Up to ${limit} questions` : undefined}
                        onClick={() => setDialog({ faq: null })}
                    >
                        <Plus data-icon="inline-start" />
                        Add question
                    </Button>
                </div>
            }
        >
            <div className="overflow-x-auto rounded-xl border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-24">Hide/Show</TableHead>
                            <TableHead className="w-72">Question</TableHead>
                            <TableHead>Answer</TableHead>
                            <TableHead className="w-12">
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {faqs.length === 0 && (
                            <TableRow className="hover:bg-transparent">
                                <TableCell colSpan={4}>
                                    <EmptyState
                                        icon={CircleHelp}
                                        title="No questions yet"
                                        description="Use Add question for the questions your front desk answers most often."
                                    />
                                </TableCell>
                            </TableRow>
                        )}
                        {topics.map((topic) => (
                            <Fragment key={topic}>
                                <TableRow className="bg-muted/50 hover:bg-muted/50">
                                    <TableCell
                                        colSpan={4}
                                        className={
                                            topic
                                                ? 'font-medium'
                                                : 'text-muted-foreground'
                                        }
                                    >
                                        {topic || 'No topic'}
                                    </TableCell>
                                </TableRow>
                                {faqs
                                    .filter(
                                        (faq) => (faq.topic ?? '') === topic,
                                    )
                                    .map((faq) => (
                                        <FaqRow
                                            key={faq.id}
                                            faq={faq}
                                            onEdit={() => setDialog({ faq })}
                                            onDelete={() => setDeleting(faq)}
                                        />
                                    ))}
                            </Fragment>
                        ))}
                    </TableBody>
                </Table>
            </div>
            {dialog && (
                <FaqDialog
                    key={dialog.faq?.id ?? 'new'}
                    faq={dialog.faq}
                    topics={topics.filter(Boolean)}
                    onClose={() => setDialog(null)}
                />
            )}
            <DeleteFaqDialog
                faq={deleting}
                onOpenChange={(open) => !open && setDeleting(null)}
            />
        </PanelSection>
    );
}

/** One question: the Hide/Show switch saves at once; the menu edits or deletes. */
function FaqRow({
    faq,
    onEdit,
    onDelete,
}: {
    faq: Faq;
    onEdit: () => void;
    onDelete: () => void;
}) {
    return (
        <TableRow>
            <TableCell>
                <Switch
                    size="sm"
                    checked={faq.active}
                    aria-label={`${faq.active ? 'Hide' : 'Show'} ${faq.question} ${faq.active ? 'from' : 'to'} guests`}
                    onCheckedChange={(next) =>
                        router.put(
                            active.url({ faq: faq.id }),
                            { active: next },
                            keepPage,
                        )
                    }
                />
            </TableCell>
            <TableCell className="font-medium whitespace-normal">
                {faq.question}
            </TableCell>
            <TableCell className="text-pretty whitespace-pre-wrap text-muted-foreground">
                {faq.answer}
            </TableCell>
            <TableCell>
                <div className="flex justify-end">
                    <DropdownMenu>
                        <DropdownMenuTrigger
                            render={
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label={`Actions for ${faq.question}`}
                                />
                            }
                        >
                            <Ellipsis />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem onClick={onEdit}>
                                <Pencil />
                                Edit
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                onClick={onDelete}
                            >
                                <Trash2 />
                                Delete
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </TableCell>
        </TableRow>
    );
}

/**
 * The topic of a question: pick one the property already uses, or type a new one and pick "Add". Picking keeps the same
 * spelling, so questions land in one group in the guide. The clear button leaves the question with no topic.
 */
function TopicField({
    id,
    value,
    topics,
    onChange,
    invalid,
}: {
    id: string;
    value: string;
    topics: string[];
    onChange: (topic: string) => void;
    invalid: boolean;
}) {
    const [query, setQuery] = useState('');
    const typed = query.trim();
    const isNew =
        typed !== '' &&
        !topics.some((topic) => topic.toLowerCase() === typed.toLowerCase());
    const items = isNew ? [...topics, typed] : topics;

    return (
        <Combobox
            items={items}
            value={value || null}
            onValueChange={(next) => onChange((next as string | null) ?? '')}
            onInputValueChange={(next, details) => {
                if (details.reason !== 'item-press') {
                    setQuery(next);
                }
            }}
            autoHighlight
        >
            <ComboboxInput
                id={id}
                className="w-full"
                placeholder="e.g. Breakfast"
                maxLength={100}
                autoComplete="off"
                showClear
                aria-invalid={invalid}
            />
            <ComboboxContent>
                <ComboboxEmpty>Type a topic to add it.</ComboboxEmpty>
                <ComboboxList>
                    {(topic: string) => (
                        <ComboboxItem key={topic} value={topic}>
                            {isNew && topic === typed
                                ? `Add “${topic}”`
                                : topic}
                        </ComboboxItem>
                    )}
                </ComboboxList>
            </ComboboxContent>
        </Combobox>
    );
}

/** The dialog to add a question (`faq` null) or change one's topic, question and answer. */
function FaqDialog({
    faq,
    topics,
    onClose,
}: {
    faq: Faq | null;
    topics: string[];
    onClose: () => void;
}) {
    const form = useForm({
        topic: faq?.topic ?? '',
        question: faq?.question ?? '',
        answer: faq?.answer ?? '',
    });
    const { data, errors } = form;
    const options = { ...keepPage, onSuccess: onClose };

    return (
        <FormOverlay
            variant="dialog"
            open
            onOpenChange={(open) => !open && onClose()}
            title={faq ? 'Edit question' : 'Add a question'}
            description="Guests see the question and answer in the property guide, under its topic."
            errors={errors}
            onSubmit={() =>
                faq
                    ? form.put(update.url({ faq: faq.id }), options)
                    : form.post(store.url(), options)
            }
            submitLabel={faq ? 'Save question' : 'Add question'}
            processing={form.processing}
        >
            <Field data-invalid={!!errors.topic}>
                <FieldLabel htmlFor="faq_topic">Topic (optional)</FieldLabel>
                <TopicField
                    id="faq_topic"
                    value={data.topic}
                    topics={topics}
                    onChange={(topic) => form.setData('topic', topic)}
                    invalid={!!errors.topic}
                />
                <FieldError>{errors.topic}</FieldError>
            </Field>
            <Field data-invalid={!!errors.question}>
                <FieldLabel htmlFor="faq_question">Question</FieldLabel>
                <Input
                    id="faq_question"
                    placeholder="e.g. Is breakfast included?"
                    value={data.question}
                    maxLength={200}
                    onChange={(event) =>
                        form.setData('question', event.target.value)
                    }
                    aria-invalid={!!errors.question}
                />
                <FieldError>{errors.question}</FieldError>
            </Field>
            <Field data-invalid={!!errors.answer}>
                <FieldLabel htmlFor="faq_answer">Answer</FieldLabel>
                <Textarea
                    id="faq_answer"
                    placeholder="e.g. Yes. Breakfast is served from 7 to 10 am at the lobby café."
                    value={data.answer}
                    rows={5}
                    maxLength={1000}
                    onChange={(event) =>
                        form.setData('answer', event.target.value)
                    }
                    aria-invalid={!!errors.answer}
                />
                <FieldError>{errors.answer}</FieldError>
            </Field>
        </FormOverlay>
    );
}

/** Confirms deleting a question. It cannot be undone. */
function DeleteFaqDialog({
    faq,
    onOpenChange,
}: {
    faq: Faq | null;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <AlertDialog open={faq !== null} onOpenChange={onOpenChange}>
            {faq && (
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Delete “{faq.question}”?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Guests will no longer see this question. This cannot
                            be undone. To hide it for a while, switch it off
                            instead.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <Form
                        {...destroy.form({ faq: faq.id })}
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
                                    Delete question
                                </Button>
                            </AlertDialogFooter>
                        )}
                    </Form>
                </AlertDialogContent>
            )}
        </AlertDialog>
    );
}

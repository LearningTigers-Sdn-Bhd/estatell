import { Form } from '@inertiajs/react';
import {
    ClipboardList,
    MessageSquare,
    Send,
    ConciergeBell,
} from 'lucide-react';
import { GuestFrame } from '@/components/concierge/stay-menu';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { store } from '@/routes/concierge/requests';

/** Send a request and keep the front desk's replies alongside the form. */
export default function ConciergeRequests({
    token,
    back,
    requests,
    types,
    selectedType,
}: {
    token: string;
    back: { href: string; label: string };
    requests: App.Data.Concierge.ConciergeRequestData[];
    types: EnumOption[];
    selectedType: string;
}) {
    return (
        <GuestFrame
            back={back}
            title="Guest requests"
            description="Request cleaning or supplies, report an issue, and see updates from the front desk."
        >
            <div className="grid items-start gap-8 lg:grid-cols-5 lg:gap-12">
                <div className="flex flex-col gap-6 lg:col-span-3">
                    <section
                        className="flex flex-col gap-6 rounded-2xl border border-border/40 bg-card p-5 sm:p-8"
                        aria-labelledby="new-request-title"
                    >
                        <div className="flex items-center gap-3">
                            <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-muted/60 text-primary">
                                <ConciergeBell className="size-5" aria-hidden />
                            </span>
                            <div className="flex flex-col gap-1">
                                <h2
                                    id="new-request-title"
                                    className="text-xl font-semibold"
                                >
                                    How can we help?
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Tell us what you need during your stay.
                                </p>
                            </div>
                        </div>
                        <Form
                            action={store.url({ token })}
                            method="post"
                            resetOnSuccess
                        >
                            {({ processing, errors }) => (
                                <div className="flex flex-col gap-6">
                                    <FieldGroup>
                                        <Field data-invalid={!!errors.type}>
                                            <FieldLabel htmlFor="type">
                                                Request type
                                            </FieldLabel>
                                            <EnumSelect
                                                id="type"
                                                name="type"
                                                options={types}
                                                defaultValue={selectedType}
                                                className="min-h-12 w-full"
                                                aria-invalid={!!errors.type}
                                            />
                                            <FieldError>
                                                {errors.type}
                                            </FieldError>
                                        </Field>
                                        <Field data-invalid={!!errors.details}>
                                            <FieldLabel htmlFor="details">
                                                What do you need?
                                            </FieldLabel>
                                            <Textarea
                                                id="details"
                                                name="details"
                                                placeholder="Tell us what you need or describe the issue…"
                                                maxLength={1000}
                                                required
                                                aria-invalid={!!errors.details}
                                                className="min-h-40 text-base"
                                            />
                                            <FieldDescription>
                                                Include any details that will
                                                help the team. Up to 1,000
                                                characters.
                                            </FieldDescription>
                                            <FieldError>
                                                {errors.details}
                                            </FieldError>
                                        </Field>
                                    </FieldGroup>
                                    <Button
                                        type="submit"
                                        size="lg"
                                        className="min-h-12 w-full sm:w-fit sm:px-5"
                                        disabled={processing}
                                        focusableWhenDisabled
                                    >
                                        {processing ? (
                                            <Spinner data-icon="inline-start" />
                                        ) : (
                                            <Send
                                                data-icon="inline-start"
                                                aria-hidden
                                            />
                                        )}
                                        Send request
                                    </Button>
                                </div>
                            )}
                        </Form>
                    </section>
                </div>
                <section
                    className="flex flex-col gap-5 lg:col-span-2"
                    aria-labelledby="request-history-title"
                >
                    <div className="flex items-center justify-between gap-3 border-b border-border/40 pb-4">
                        <h2
                            id="request-history-title"
                            className="text-lg font-semibold"
                        >
                            Your requests
                        </h2>
                        <span className="text-sm text-muted-foreground tabular-nums">
                            {requests.length}
                        </span>
                    </div>
                    {requests.length === 0 && (
                        <div className="flex flex-col items-center gap-4 rounded-2xl bg-muted/40 px-6 py-10 text-center">
                            <ClipboardList
                                className="size-8 text-primary"
                                strokeWidth={1.5}
                                aria-hidden
                            />
                            <div className="flex max-w-xs flex-col gap-2">
                                <h3 className="font-medium">No requests yet</h3>
                                <p className="text-sm leading-relaxed text-muted-foreground">
                                    Your requests and updates from the front
                                    desk will appear here.
                                </p>
                            </div>
                        </div>
                    )}
                    <ul className="flex flex-col gap-4">
                        {requests.map((request) => (
                            <li
                                key={request.id}
                                className="flex flex-col gap-4 rounded-2xl border border-border/40 bg-card p-5"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <h3 className="font-medium">
                                        {request.type}
                                    </h3>
                                    <StatusBadge status={request.status} />
                                </div>
                                <p className="text-sm leading-relaxed whitespace-pre-wrap">
                                    {request.details}
                                </p>
                                {request.note && (
                                    <div className="flex items-start gap-2 rounded-xl bg-muted/50 p-3">
                                        <MessageSquare
                                            className="mt-0.5 size-4 shrink-0 text-primary"
                                            aria-hidden
                                        />
                                        <div className="flex flex-col gap-1">
                                            <p className="text-xs font-medium">
                                                Front desk update
                                            </p>
                                            <p className="text-sm leading-relaxed whitespace-pre-wrap text-muted-foreground">
                                                {request.note}
                                            </p>
                                        </div>
                                    </div>
                                )}
                                <time
                                    dateTime={request.openedAt}
                                    className="text-xs text-muted-foreground tabular-nums"
                                >
                                    {new Date(
                                        request.openedAt,
                                    ).toLocaleString()}
                                </time>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </GuestFrame>
    );
}

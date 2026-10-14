import { Form, Head, Link, usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
import { store } from '@/routes/view-as';

type Staff = App.Data.Platform.ViewAsStaffData;
type Reason = { value: string; label: string };

export default function ViewAsShow({
    staff,
    defaultUserId,
    reasons,
    reason: initialReason,
    to,
    opens,
    consent,
    cancelUrl,
}: {
    staff: Staff[];
    defaultUserId: number | null;
    reasons: Reason[];
    reason: string;
    to: string | null;
    opens: string;
    consent: boolean;
    cancelUrl: string;
}) {
    const { property, supportTwoFactor } = usePage().props;
    const [userId, setUserId] = useState(String(defaultUserId ?? ''));
    const [reason, setReason] = useState(initialReason);

    if (!property) {
        return null;
    }

    const userOptions = staff.map((member) => ({
        value: String(member.id),
        label: member.role ? `${member.name} (${member.role})` : member.name,
    }));
    const chosen = staff.find((member) => String(member.id) === userId);
    const blocked = !consent
        ? 'Owner has disabled support access'
        : supportTwoFactor?.required
          ? 'Set up two-factor to view properties as their owner'
          : null;

    return (
        <>
            <Head title={`View as ${property.name}`} />

            <div className="flex flex-1 items-start justify-center p-4 pt-12">
                <Card className="w-full max-w-lg">
                    <Form {...store.form(property.code)} className="contents">
                        {({ processing, errors }) => (
                            <>
                                <CardHeader>
                                    <CardTitle>
                                        View as {property.name} ({property.code}
                                        )
                                    </CardTitle>
                                    <CardDescription>
                                        You are signed in as support. The owner
                                        is told about this session.
                                    </CardDescription>
                                </CardHeader>

                                <CardContent>
                                    <FieldGroup>
                                        {blocked && (
                                            <p
                                                role="alert"
                                                className="flex items-center gap-2 rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-sm"
                                            >
                                                <TriangleAlert className="size-4 shrink-0 text-warning" />
                                                {blocked}
                                            </p>
                                        )}

                                        <Field data-invalid={!!errors.user}>
                                            <FieldLabel htmlFor="user">
                                                User
                                            </FieldLabel>
                                            <EnumSelect
                                                id="user"
                                                name="user"
                                                options={userOptions}
                                                value={userId}
                                                onValueChange={setUserId}
                                                disabled={!!blocked}
                                                aria-invalid={!!errors.user}
                                            />
                                            <FieldError>
                                                {errors.user}
                                            </FieldError>
                                        </Field>

                                        <Field data-invalid={!!errors.reason}>
                                            <FieldLabel>Reason</FieldLabel>
                                            <input
                                                type="hidden"
                                                name="reason"
                                                value={reason}
                                            />
                                            <ToggleGroup
                                                variant="outline"
                                                value={[reason]}
                                                onValueChange={(next) => {
                                                    if (next[0]) {
                                                        setReason(
                                                            String(next[0]),
                                                        );
                                                    }
                                                }}
                                                className="flex-wrap"
                                            >
                                                {reasons.map((option) => (
                                                    <ToggleGroupItem
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </ToggleGroupItem>
                                                ))}
                                            </ToggleGroup>
                                            <FieldError>
                                                {errors.reason}
                                            </FieldError>
                                        </Field>

                                        <Field data-invalid={!!errors.note}>
                                            <FieldLabel htmlFor="note">
                                                Note
                                            </FieldLabel>
                                            <Input
                                                id="note"
                                                name="note"
                                                maxLength={500}
                                                placeholder="Optional"
                                                aria-invalid={!!errors.note}
                                            />
                                            <FieldError>
                                                {errors.note}
                                            </FieldError>
                                        </Field>

                                        {to && (
                                            <input
                                                type="hidden"
                                                name="to"
                                                value={to}
                                            />
                                        )}
                                        <p className="text-sm text-muted-foreground">
                                            Opens{' '}
                                            <span className="text-foreground tabular-nums">
                                                {opens}
                                            </span>
                                        </p>
                                    </FieldGroup>
                                </CardContent>

                                <CardFooter className="justify-end gap-2">
                                    <Link
                                        href={cancelUrl}
                                        className={cn(
                                            buttonVariants({
                                                variant: 'outline',
                                            }),
                                        )}
                                    >
                                        Cancel
                                    </Link>
                                    <Button
                                        type="submit"
                                        disabled={
                                            processing || !!blocked || !chosen
                                        }
                                        focusableWhenDisabled
                                    >
                                        {processing && (
                                            <Spinner data-icon="inline-start" />
                                        )}
                                        View as {chosen?.name ?? 'user'}
                                    </Button>
                                </CardFooter>
                            </>
                        )}
                    </Form>
                </Card>
            </div>
        </>
    );
}

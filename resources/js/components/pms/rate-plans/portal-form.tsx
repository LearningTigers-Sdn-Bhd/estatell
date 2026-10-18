import { Link, useForm } from '@inertiajs/react';
import { EyeOff, UserCheck, Users } from 'lucide-react';
import { ChoiceCards } from '@/components/pms/choice-cards';
import type { Choice } from '@/components/pms/choice-cards';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { rates } from '@/routes/finance/city-ledger/companies';
import { update } from '@/routes/settings/rate-plans/portal';

type RatePlan = App.Data.Rates.RatePlanData;
type Portal = App.Data.Rates.RatePlanPortalData;

const CHOICES: Record<string, Pick<Choice, 'icon' | 'description'>> = {
    hidden: {
        icon: EyeOff,
        description: 'Companies cannot book this plan in the portal.',
    },
    all: {
        icon: Users,
        description:
            'Every company with a portal login can book it, except when the plan is linked to other companies.',
    },
    selected: {
        icon: UserCheck,
        description:
            'Only the companies linked to this plan can book it. For derived plans with at least one linked company.',
    },
};

/**
 * Who can book the plan in the company portal. The linked companies are read only here: each company's Rates tab makes
 * the links.
 */
export function RatePlanPortalForm({
    ratePlan,
    portal,
    accessOptions,
    canViewCompanies,
}: {
    ratePlan: RatePlan;
    portal: Portal;
    accessOptions: { value: string; label: string }[];
    canViewCompanies: boolean;
}) {
    const form = useForm({ portal_access: portal.portalAccess as string });
    const guard = useUnsavedChanges(form.isDirty);
    const errors = form.errors as Record<string, string | undefined>;

    const save = (onSaved?: () => void) => {
        form.put(update.url({ ratePlan: ratePlan.id }), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                form.setDefaults();
                onSaved?.();
            },
        });
    };

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                save();
            }}
            className="flex flex-col gap-5"
        >
            <ChoiceCards
                id="portal-access"
                name="portal_access"
                legend="Who can book this plan in the portal"
                choices={accessOptions.map((option) => ({
                    value: option.value,
                    label: option.label,
                    ...CHOICES[option.value],
                }))}
                value={form.data.portal_access}
                onValueChange={(value) => form.setData('portal_access', value)}
                error={errors.portal_access}
            />

            <section className="flex flex-col gap-2">
                <h2 className="text-sm font-medium">Linked companies</h2>
                {portal.companies.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {portal.isDerived
                            ? "No company is linked yet. Link this plan on a company's Rates tab."
                            : 'Only a derived plan can be linked to companies.'}
                    </p>
                ) : (
                    <ul className="flex flex-col gap-1 text-sm">
                        {portal.companies.map((company) => (
                            <li key={company.id}>
                                {canViewCompanies ? (
                                    <Link
                                        href={rates({ company: company.id })}
                                        className="underline-offset-4 hover:underline"
                                    >
                                        {company.name}
                                    </Link>
                                ) : (
                                    company.name
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <footer className="flex justify-end">
                <Button
                    type="submit"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Save portal access
                </Button>
            </footer>
            <UnsavedChangesDialog
                guard={guard}
                name={ratePlan.name}
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
        </form>
    );
}

import { Link } from '@inertiajs/react';
import { ScrollText } from 'lucide-react';
import { TextField } from '@/components/pms/concierge/content-fields';
import { PanelSection } from '@/components/pms/concierge/guide-layout';
import { SettingsForm } from '@/components/pms/concierge/settings-form';
import { EmptyState } from '@/components/pms/empty-state';
import { buttonVariants } from '@/components/ui/button';
import { index as roomRevenue } from '@/routes/settings/room-revenue';

/** The charge policy wording guests read. It comes from Room revenue, so this only shows it. */
export function ChargePolicies({
    policyTerms,
}: {
    policyTerms: App.Data.Concierge.ConciergePolicyTermData[];
}) {
    return (
        <PanelSection
            title="Charge policies"
            description="Guests read this wording with each charge policy. Edit the source in Room revenue."
        >
            {policyTerms.length > 0 ? (
                <ul className="flex flex-col gap-3">
                    {policyTerms.map((term) => (
                        <li
                            key={term.label}
                            className="flex flex-col gap-1 border-b pb-3 text-sm last:border-b-0 last:pb-0"
                        >
                            <span className="font-medium">{term.label}</span>
                            <span className="text-pretty text-muted-foreground">
                                {term.text}
                            </span>
                        </li>
                    ))}
                </ul>
            ) : (
                <EmptyState
                    icon={ScrollText}
                    title="No charge policies yet"
                    description="Policy wording will appear here after you add it in Room revenue."
                />
            )}
            <Link
                href={roomRevenue.url()}
                className={buttonVariants({
                    variant: 'outline',
                    className: 'self-start',
                })}
            >
                Edit in Room revenue
            </Link>
        </PanelSection>
    );
}

/** House rules and the policy texts, which staff write here. The guest action switches are sent along unchanged. */
export function HouseRulesForm({
    houseRules,
    policyTexts,
    walkInEnabled,
    refundRequestsEnabled,
}: {
    houseRules: string | null;
    policyTexts: {
        roomPolicy: string | null;
        paymentPolicy: string | null;
        otherPolicies: string | null;
    };
    walkInEnabled: boolean;
    refundRequestsEnabled: boolean;
}) {
    return (
        <SettingsForm
            title="House rules and terms"
            description="Set the expectations guests should know before they arrive. Leave a box empty to hide it from guests."
            kept={{
                walk_in_enabled: walkInEnabled,
                refund_requests_enabled: refundRequestsEnabled,
            }}
        >
            {(errors) => (
                <>
                    <TextField
                        name="house_rules"
                        label="Rules for guests"
                        defaultValue={houseRules}
                        error={errors.house_rules}
                        rows={8}
                        maxLength={2000}
                        placeholder="e.g. Quiet hours from 10 pm. No smoking in the rooms."
                    />
                    <TextField
                        name="room_policy"
                        label="Room terms"
                        defaultValue={policyTexts.roomPolicy}
                        error={errors.room_policy}
                        rows={4}
                        maxLength={2000}
                        placeholder="e.g. Children under 12 stay free. Cots on request. Pets are not allowed. Extra cleaning is charged."
                    />
                    <TextField
                        name="payment_policy"
                        label="Payment and deposits"
                        defaultValue={policyTexts.paymentPolicy}
                        error={errors.payment_policy}
                        rows={4}
                        maxLength={2000}
                        placeholder="e.g. Pay at check-in. A RM 100 deposit is held and returned at check-out."
                    />
                    <TextField
                        name="other_policies"
                        label="Other policies"
                        defaultValue={policyTexts.otherPolicies}
                        error={errors.other_policies}
                        rows={4}
                        maxLength={2000}
                        placeholder="e.g. Lost property is kept for 30 days."
                    />
                </>
            )}
        </SettingsForm>
    );
}

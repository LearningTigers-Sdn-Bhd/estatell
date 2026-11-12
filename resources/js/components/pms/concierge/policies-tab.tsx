import { Link } from '@inertiajs/react';
import { ScrollText } from 'lucide-react';
import { TextField } from '@/components/pms/concierge/content-fields';
import { ContentGrid } from '@/components/pms/concierge/guide-layout';
import { SettingsForm } from '@/components/pms/concierge/settings-form';
import { EmptyState } from '@/components/pms/empty-state';
import { buttonVariants } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { index as roomRevenue } from '@/routes/settings/room-revenue';

/** House rules, which staff write here, and the charge policy wording, which comes from Room revenue. */
export function PoliciesTab({
    houseRules,
    policyTexts,
    policyTerms,
    walkInEnabled,
    refundRequestsEnabled,
}: {
    houseRules: string | null;
    policyTexts: {
        roomPolicy: string | null;
        paymentPolicy: string | null;
        otherPolicies: string | null;
    };
    policyTerms: App.Data.Concierge.ConciergePolicyTermData[];
    walkInEnabled: boolean;
    refundRequestsEnabled: boolean;
}) {
    return (
        <ContentGrid
            aside={
                <Card>
                    <CardHeader>
                        <CardTitle>Charge policies</CardTitle>
                        <CardDescription>
                            Guests read this wording with each charge policy.
                            Edit the source in Room revenue.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {policyTerms.length > 0 ? (
                            <ul className="flex flex-col gap-3">
                                {policyTerms.map((term) => (
                                    <li
                                        key={term.label}
                                        className="flex flex-col gap-1 border-b pb-3 text-sm last:border-b-0 last:pb-0"
                                    >
                                        <span className="font-medium">
                                            {term.label}
                                        </span>
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
                    </CardContent>
                    <CardFooter>
                        <Link
                            href={roomRevenue.url()}
                            className={buttonVariants({
                                variant: 'outline',
                                className: 'min-h-11',
                            })}
                        >
                            Edit in Room revenue
                        </Link>
                    </CardFooter>
                </Card>
            }
        >
            <Card>
                <CardHeader>
                    <CardTitle>House rules and terms</CardTitle>
                    <CardDescription>
                        Set the expectations guests should know before they
                        arrive. Leave a box empty to hide it from guests.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <SettingsForm
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
                </CardContent>
            </Card>
        </ContentGrid>
    );
}

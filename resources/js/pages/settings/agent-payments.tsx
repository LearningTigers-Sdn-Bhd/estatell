import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/pms/page-header';
import { SettingsSection } from '@/components/pms/settings-section';
import { SettingsInput } from '@/components/pms/settings/form-fields';
import { edit, update } from '@/routes/settings/agent-payments';

type Terms = {
    depositPercent: number;
    depositHours: number;
    fullPaymentDays: number;
};

export default function AgentPaymentsSettings({ terms }: { terms: Terms }) {
    return (
        <>
            <Head title="Agent payments" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Agent payments"
                    description="When a company that pays per booking (Pay at check-out) pays: a deposit soon after booking, then the rest before arrival. A company can have its own terms on its credit tab."
                />

                <SettingsSection
                    form={update.form()}
                    title="Payment schedule"
                    description="Bookings already made keep their deadlines. The amounts follow the whole booking: rooms, extras and taxes."
                    submitLabel="Save terms"
                >
                    {(errors) => (
                        <>
                            <SettingsInput
                                name="agent_deposit_percent"
                                label="Deposit (%)"
                                type="number"
                                min={1}
                                max={100}
                                value={terms.depositPercent}
                                error={errors.agent_deposit_percent}
                                description="Share of the whole booking paid first. 100 asks for everything at once."
                            />
                            <SettingsInput
                                name="agent_deposit_hours"
                                label="Deposit due within (hours)"
                                type="number"
                                min={1}
                                max={8760}
                                value={terms.depositHours}
                                error={errors.agent_deposit_hours}
                                description="72 is 3 days. Never later than arrival."
                            />
                            <SettingsInput
                                name="agent_full_payment_days"
                                label="Full payment (days before arrival)"
                                type="number"
                                min={1}
                                max={365}
                                value={terms.fullPaymentDays}
                                error={errors.agent_full_payment_days}
                                description="A booking made later than this pays everything within the deposit time."
                            />
                        </>
                    )}
                </SettingsSection>
            </div>
        </>
    );
}

AgentPaymentsSettings.layout = {
    breadcrumbs: [{ title: 'Agent payments', href: edit() }],
};

import { Form, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { SettingsSection } from '@/components/pms/settings-section';
import { SettingsInput } from '@/components/pms/settings/form-fields';
import { StatusBadge } from '@/components/pms/status-badge';
import { ResetTemporaryPassword } from '@/components/pms/temporary-passwords/reset-temporary-password';
import { TemporaryPasswordMessageDialog } from '@/components/pms/temporary-passwords/temporary-password-message-dialog';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/finance/city-ledger/companies';
import {
    message,
    reset,
    resend,
} from '@/routes/finance/city-ledger/companies/portal';

type Company = App.Data.CityLedger.CompanyData;

/**
 * The company's one portal login on the Portal tab: the email and the free cancellation days, then the login's status
 * with Copy message, Reset password and Resend. The status row is a sibling of the form, because it has forms of its own.
 * After a save or a reset that makes a temp password, the server flashes `portalLoginCreated` and the message opens.
 */
export function CompanyPortalSection({
    company,
    readOnly,
}: {
    company: Company;
    readOnly: boolean;
}) {
    const portal = company.portal;
    const { flash } = usePage();
    const [copying, setCopying] = useState(false);
    const [resetting, setResetting] = useState(false);
    const disabled = readOnly || (portal?.isDisabled ?? false);
    const showCopy = portal?.hasTempPassword ?? false;
    const created = Boolean(
        (flash as { portalLoginCreated?: boolean } | undefined)
            ?.portalLoginCreated,
    );

    useEffect(() => {
        if (created) {
            setCopying(true);
        }
    }, [created, flash]);

    return (
        <div className="flex flex-col gap-4">
            <SettingsSection
                key={`portal-${company.id}-${portal?.email ?? ''}`}
                form={update.form({ company: company.id, section: 'portal' })}
                title="Portal"
                description={
                    portal?.isDisabled
                        ? 'This company is suspended. Reactivate it to change its portal login.'
                        : 'One login for the company portal, where the company books and reads its statements.'
                }
                submitLabel="Save portal"
                readOnly={readOnly || (portal?.isDisabled ?? false)}
            >
                {(errors) => (
                    <>
                        <SettingsInput
                            name="portal_email"
                            label="Portal login email"
                            type="email"
                            placeholder="e.g. booking@borneotravel.my"
                            value={portal?.email ?? null}
                            disabled={disabled}
                            error={errors.portal_email}
                            description="Empty means no portal for this company. It can be the same as the contact email."
                        />
                        <SettingsInput
                            name="portal_cancel_cutoff_days"
                            label="Free cancellation until (days before arrival)"
                            type="number"
                            min={0}
                            max={365}
                            placeholder="e.g. 7"
                            value={portal?.cancelDays ?? null}
                            disabled={disabled}
                            error={errors.portal_cancel_cutoff_days}
                            description="Empty means they can only request a cancellation."
                        />
                    </>
                )}
            </SettingsSection>

            {portal?.status && (
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3">
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        <span className="text-muted-foreground">Status</span>
                        <StatusBadge status={portal.status} />
                        {portal.hasTempPassword && (
                            <span className="text-muted-foreground">
                                Temp password · {portal.tempPasswordDaysLeft}{' '}
                                {portal.tempPasswordDaysLeft === 1
                                    ? 'day'
                                    : 'days'}
                            </span>
                        )}
                        {portal.tempPasswordExpired && (
                            <span className="text-muted-foreground">
                                Temp password expired
                            </span>
                        )}
                    </div>
                    {!readOnly && (
                        <div className="flex flex-wrap gap-2">
                            {showCopy && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setCopying(true)}
                                >
                                    Copy message
                                </Button>
                            )}
                            {portal.canReset && (
                                <ResetTemporaryPassword
                                    route={reset({ company: company.id })}
                                    userIds={[]}
                                    target={company.name}
                                    skippedNote="Send them the new message afterwards."
                                    variant="outline"
                                    open={resetting}
                                    onOpenChange={setResetting}
                                    onReset={() => setResetting(false)}
                                />
                            )}
                            {portal.canReset && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setResetting(true)}
                                >
                                    Reset password
                                </Button>
                            )}
                            {portal.canResend && (
                                <Form
                                    {...resend.form({ company: company.id })}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            size="sm"
                                            disabled={processing}
                                            focusableWhenDisabled
                                        >
                                            {processing && (
                                                <Spinner data-icon="inline-start" />
                                            )}
                                            Resend
                                        </Button>
                                    )}
                                </Form>
                            )}
                        </div>
                    )}
                </div>
            )}

            <TemporaryPasswordMessageDialog
                route={message({ company: company.id })}
                who="company"
                userIds={[]}
                open={copying}
                onOpenChange={setCopying}
            />
        </div>
    );
}

import { router } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    OutletPaymentMethodsCard,
    OutletRegistersCard,
    OutletSettingsForm,
} from '@/components/pms/pos/pos-outlet-settings-cards';
import type {
    OutletPaymentMethodRow,
    OutletSettingsRecord,
} from '@/components/pms/pos/pos-outlet-settings-cards';
import {
    PosSettingsShell,
    posSettingsBreadcrumbs,
} from '@/components/pms/pos/pos-settings-tabs';
import { index as outletsIndex } from '@/routes/settings/pos/outlets';

type Props = {
    outlets: EnumOption[];
    outlet: OutletSettingsRecord | null;
    registers: { id: number; name: string; active: boolean }[];
    outletPaymentMethods: OutletPaymentMethodRow[];
    paymentMethods: EnumOption[];
    tenderTypes: EnumOption[];
    transactionCodes: EnumOption[];
    operationModes: EnumOption[];
    roundingModes: EnumOption[];
    roundingPolicies: EnumOption[];
};

export default function PosOutletSettingsPage({
    outlets,
    outlet,
    registers,
    outletPaymentMethods,
    paymentMethods,
    tenderTypes,
    transactionCodes,
    operationModes,
    roundingModes,
    roundingPolicies,
}: Props) {
    return (
        <PosSettingsShell activeTab="registers">
            <div className="flex flex-col gap-6">
                {outlets.length > 1 && (
                    <EnumSelect
                        name="outlet"
                        options={outlets}
                        value={String(outlet?.id ?? '')}
                        onValueChange={(value) =>
                            router.get(
                                outletsIndex.url(undefined, {
                                    query: { outlet: value },
                                }),
                            )
                        }
                        aria-label="Outlet"
                    />
                )}
                {outlet === null ? (
                    <p className="text-sm text-muted-foreground">
                        Enable POS on an outlet on the Outlets tab first.
                    </p>
                ) : (
                    <>
                        <OutletSettingsForm
                            key={outlet.id}
                            outlet={outlet}
                            codes={transactionCodes}
                            operationModes={operationModes}
                            roundingModes={roundingModes}
                            roundingPolicies={roundingPolicies}
                        />
                        <OutletPaymentMethodsCard
                            outletId={outlet.id}
                            rows={outletPaymentMethods}
                            paymentMethods={paymentMethods}
                            tenderTypes={tenderTypes}
                        />
                        <OutletRegistersCard
                            outletId={outlet.id}
                            registers={registers}
                        />
                    </>
                )}
            </div>
        </PosSettingsShell>
    );
}

PosOutletSettingsPage.layout = {
    breadcrumbs: posSettingsBreadcrumbs('registers'),
};

import { router } from '@inertiajs/react';
import { Landmark, Monitor, SlidersHorizontal, Store } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
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
    OutletSettingsSection,
} from '@/components/pms/pos/pos-outlet-settings-cards';
import {
    PosSettingsShell,
    posSettingsBreadcrumbs,
} from '@/components/pms/pos/pos-settings-tabs';
import { cn } from '@/lib/utils';
import { index as outletsIndex } from '@/routes/settings/pos/outlets';

type Props = {
    outlets: EnumOption[];
    outlet: OutletSettingsRecord | null;
    registers: { id: number; name: string; active: boolean }[];
    outletPaymentMethods: OutletPaymentMethodRow[];
    paymentMethods: EnumOption[];
    tenderTypes: EnumOption[];
    transactionCodes: EnumOption[];
    chargeCodes: EnumOption[];
    operationModes: EnumOption[];
    roundingModes: EnumOption[];
    roundingPolicies: EnumOption[];
};

type PageTab = 'registers' | OutletSettingsSection;

export default function PosOutletSettingsPage({
    outlets,
    outlet,
    registers,
    outletPaymentMethods,
    paymentMethods,
    tenderTypes,
    transactionCodes,
    chargeCodes,
    operationModes,
    roundingModes,
    roundingPolicies,
}: Props) {
    const [tab, setTab] = useState<PageTab>('registers');

    const counts = useMemo(() => {
        if (outlet === null) {
            return null;
        }

        const activeRegisters = registers.filter((row) => row.active).length;
        const hasActiveCash = outletPaymentMethods.some(
            (row) => row.active && row.tenderType === 'cash',
        );
        const mappedAccounts = [
            outlet.serviceChargeTransactionCodeId,
            outlet.packagingTransactionCodeId,
            outlet.roundingTransactionCodeId,
            outlet.cashVarianceTransactionCodeId,
            outlet.safeClearingTransactionCodeId,
        ].filter((id) => id !== null).length;

        const enabledControls = [
            outlet.requireRoomChargeSignature,
            outlet.requireMealRedemptionConfirmation,
            outlet.requireReprintPin,
            outlet.requireDualControl,
            outlet.printReceiptAfterPayment,
            outlet.printDrawerSlip,
            outlet.requireClockIn,
            outlet.allowAnonymousQr,
            outlet.allowQrCustomerRemark,
        ].filter(Boolean).length;

        return {
            activeRegisters,
            hasActiveCash,
            mappedAccounts,
            enabledControls,
        };
    }, [outlet, registers, outletPaymentMethods]);

    const navTabs: {
        value: PageTab;
        label: string;
        icon: LucideIcon;
        badge?: string;
        attention?: boolean;
    }[] = counts
        ? [
              {
                  value: 'registers',
                  label: 'Registers & payments',
                  icon: Store,
                  badge: String(registers.length + outletPaymentMethods.length),
                  attention:
                      counts.activeRegisters === 0 || !counts.hasActiveCash,
              },
              {
                  value: 'controls',
                  label: 'Till controls & QR',
                  icon: SlidersHorizontal,
                  badge: `${counts.enabledControls}/9`,
              },
              {
                  value: 'accounting',
                  label: 'Accounting & tax',
                  icon: Landmark,
                  badge: `${counts.mappedAccounts}/5`,
                  attention: counts.mappedAccounts < 5,
              },
              {
                  value: 'display',
                  label: 'Customer display',
                  icon: Monitor,
                  badge: outlet?.cfdDuitnowQrUrl ? 'QR set' : undefined,
              },
          ]
        : [];

    const headerActions =
        outlets.length > 1 ? (
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
                icon={<Store aria-hidden />}
                aria-label="Outlet"
                className="w-48"
            />
        ) : undefined;

    return (
        <PosSettingsShell activeTab="registers" actions={headerActions}>
            {outlet === null ? (
                <div className="rounded-xl border bg-card p-8">
                    <EmptyState
                        icon={Store}
                        title="No POS outlet enabled"
                        description="Enable built-in POS on at least one outlet under Outlets first to configure registers, payment methods, and accounting codes."
                    />
                </div>
            ) : (
                <>
                    <nav
                        aria-label="Outlet configuration sections"
                        className="flex items-center justify-between gap-3 border-b"
                    >
                        <div
                            role="tablist"
                            aria-label="Outlet configuration sections"
                            className="-mb-px flex max-w-full items-center gap-1 overflow-x-auto"
                        >
                            {navTabs.map((item) => {
                                const Icon = item.icon;
                                const active = tab === item.value;

                                return (
                                    <button
                                        key={item.value}
                                        type="button"
                                        role="tab"
                                        aria-selected={active}
                                        onClick={() => setTab(item.value)}
                                        className={cn(
                                            'inline-flex shrink-0 items-center gap-2 border-b-2 px-3.5 py-2.5 text-sm font-medium transition-colors focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none motion-reduce:transition-none max-sm:min-h-11',
                                            active
                                                ? 'border-primary text-foreground'
                                                : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground',
                                        )}
                                    >
                                        <Icon
                                            aria-hidden="true"
                                            className="size-4 shrink-0"
                                        />
                                        <span>{item.label}</span>
                                        {item.badge !== undefined && (
                                            <span
                                                className={cn(
                                                    'inline-flex items-center rounded-full px-2 py-0.5 text-xs tabular-nums',
                                                    item.attention
                                                        ? 'bg-warning/15 font-medium text-warning-text'
                                                        : active
                                                          ? 'bg-muted font-medium text-foreground'
                                                          : 'bg-muted/70 text-muted-foreground',
                                                )}
                                            >
                                                {item.badge}
                                            </span>
                                        )}
                                    </button>
                                );
                            })}
                        </div>
                    </nav>

                    {tab === 'registers' && (
                        <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
                            <OutletRegistersCard
                                outletId={outlet.id}
                                registers={registers}
                            />
                            <OutletPaymentMethodsCard
                                outletId={outlet.id}
                                rows={outletPaymentMethods}
                                paymentMethods={paymentMethods}
                                tenderTypes={tenderTypes}
                            />
                        </div>
                    )}

                    <OutletSettingsForm
                        key={outlet.id}
                        outlet={outlet}
                        activeSection={tab === 'registers' ? null : tab}
                        codes={transactionCodes}
                        chargeCodes={chargeCodes}
                        operationModes={operationModes}
                        roundingModes={roundingModes}
                        roundingPolicies={roundingPolicies}
                    />
                </>
            )}
        </PosSettingsShell>
    );
}

PosOutletSettingsPage.layout = {
    breadcrumbs: posSettingsBreadcrumbs('registers'),
};

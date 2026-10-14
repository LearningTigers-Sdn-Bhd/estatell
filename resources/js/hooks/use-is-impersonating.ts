import { usePage } from '@inertiajs/react';

/**
 * Whether a support user is viewing this property as its staff. Actions the server blocks during support access are
 * hidden while it is true.
 */
export function useIsImpersonating(): boolean {
    return usePage().props.impersonation !== null;
}

/**
 * Whether the signed-in user may refund, void and issue credit notes now. True outside a support session. During one
 * it follows the owner's `allow_support_money_corrections` switch.
 */
export function useCanCorrectMoney(): boolean {
    const impersonation = usePage().props.impersonation;

    return impersonation === null || impersonation.canCorrectMoney;
}

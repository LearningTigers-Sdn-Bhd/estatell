import { usePage } from '@inertiajs/react';

/**
 * Whether a support user is viewing this property as its staff. Actions the server blocks during support access are
 * hidden while it is true.
 */
export function useIsImpersonating(): boolean {
    return usePage().props.impersonation !== null;
}

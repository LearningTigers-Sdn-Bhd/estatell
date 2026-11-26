/**
 * An `onSuccess` for a register request. A refused POS action (wrong manager PIN, closed shift) comes back as a normal
 * redirect with an error toast, so the window must stay open with what was typed.
 */
export function unlessRefused(done: () => void) {
    return (page: { flash: unknown }) => {
        const flash = page.flash as { toast?: { type?: string } } | undefined;

        if (flash?.toast?.type !== 'error') {
            done();
        }
    };
}

import { Form, useHttp } from '@inertiajs/react';
import { RefreshCw, Search } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { resubmit } from '@/routes/finance/e-invoices';
import { find as findTaxNumber } from '@/routes/finance/e-invoices/tax-number';
import { type Submission } from '@/components/pms/einvoice/submission';

export type TaxNumberAnswer = {
    result: 'match' | 'found' | 'not_found' | 'unavailable' | 'too_many';
    tin: string | null;
    message: string;
};

/**
 * For a tax number LHDN refused: looks up the tax number LHDN holds for the ID that was sent, and sends a stay
 * e-invoice again with it in one press. A City ledger e-invoice takes the number from the company record instead.
 */
export function FindTaxNumber({ submission }: { submission: Submission }) {
    const http = useHttp<Record<string, never>, TaxNumberAnswer>({});
    const [answer, setAnswer] = useState<TaxNumberAnswer | null>(null);
    const [failed, setFailed] = useState(false);

    const ask = () => {
        setFailed(false);
        http.post(findTaxNumber.url({ submission: submission.id }))
            .then(setAnswer)
            .catch(() => setFailed(true));
    };

    return (
        <div className="flex flex-col gap-2 text-foreground">
            {answer === null && (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="self-start"
                    onClick={ask}
                    disabled={http.processing}
                    focusableWhenDisabled
                >
                    {http.processing ? (
                        <Spinner data-icon="inline-start" />
                    ) : (
                        <Search data-icon="inline-start" />
                    )}
                    Find the tax number with LHDN
                </Button>
            )}
            {failed && <p>The lookup did not go through. Try again.</p>}
            {answer && (
                <p>
                    <strong>{answer.message}</strong>
                    {answer.result === 'found' && submission.ar_invoice && (
                        <>
                            {' '}
                            Put it on the company record with{' '}
                            <strong>Edit company details</strong>, then press{' '}
                            <strong>Submit again</strong>.
                        </>
                    )}
                </p>
            )}
            {answer?.result === 'found' &&
                answer.tin &&
                submission.can_resubmit &&
                submission.reservation && (
                    <Form
                        {...resubmit.form({ submission: submission.id })}
                        transform={(data) => ({ ...data, tin: answer.tin })}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}
                                focusableWhenDisabled
                            >
                                {processing ? (
                                    <Spinner data-icon="inline-start" />
                                ) : (
                                    <RefreshCw data-icon="inline-start" />
                                )}
                                Submit again with {answer.tin}
                            </Button>
                        )}
                    </Form>
                )}
        </div>
    );
}

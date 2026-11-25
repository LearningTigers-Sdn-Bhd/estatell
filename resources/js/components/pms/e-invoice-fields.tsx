import { useHttp } from '@inertiajs/react';
import { CircleAlert, CircleCheck, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { check as checkTaxNumber } from '@/routes/finance/e-invoices/tax-number';

export const MALAYSIA_STATES = [
    { value: '01', label: 'Johor' },
    { value: '02', label: 'Kedah' },
    { value: '03', label: 'Kelantan' },
    { value: '04', label: 'Melaka' },
    { value: '05', label: 'Negeri Sembilan' },
    { value: '06', label: 'Pahang' },
    { value: '07', label: 'Pulau Pinang' },
    { value: '08', label: 'Perak' },
    { value: '09', label: 'Perlis' },
    { value: '10', label: 'Selangor' },
    { value: '11', label: 'Terengganu' },
    { value: '12', label: 'Sabah' },
    { value: '13', label: 'Sarawak' },
    { value: '14', label: 'Wilayah Persekutuan Kuala Lumpur' },
    { value: '15', label: 'Wilayah Persekutuan Labuan' },
    { value: '16', label: 'Wilayah Persekutuan Putrajaya' },
    { value: '17', label: 'Not applicable (outside Malaysia)' },
];

export const ID_TYPES = [
    { value: 'NRIC', label: 'Malaysian IC (MyKad)' },
    { value: 'PASSPORT', label: 'Passport' },
    { value: 'BRN', label: 'Company registration number' },
    { value: 'ARMY', label: 'Army or police ID' },
];

type TaxNumberAnswer = {
    result: 'match' | 'found' | 'not_found' | 'unavailable' | 'too_many';
    tin: string | null;
    message: string;
};

/**
 * Asks LHDN whether the tax number belongs to the ID before sending, so a mismatch is fixed here instead of coming
 * back as a rejection. When LHDN holds another tax number for the ID, one press puts it in the form.
 */
export function TaxNumberCheck({
    tin,
    idType,
    idValue,
    onUse,
}: {
    tin: string;
    idType: string;
    idValue: string;
    onUse: (tin: string) => void;
}) {
    const http = useHttp<
        { tin: string; id_type: string; id_value: string },
        TaxNumberAnswer
    >({ tin: '', id_type: '', id_value: '' });
    const [answer, setAnswer] = useState<{
        for: string;
        value: TaxNumberAnswer;
    } | null>(null);
    const [failed, setFailed] = useState(false);
    const asked = `${tin}|${idType}|${idValue}`;
    const current = answer?.for === asked ? answer.value : null;

    const ask = () => {
        setFailed(false);
        http.transform(() => ({ tin, id_type: idType, id_value: idValue }));
        http.post(checkTaxNumber.url())
            .then((value) => setAnswer({ for: asked, value }))
            .catch(() => setFailed(true));
    };

    return (
        <div className="flex flex-col gap-2">
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="self-start"
                onClick={ask}
                disabled={http.processing || idValue.trim() === ''}
                focusableWhenDisabled
            >
                {http.processing ? (
                    <Spinner data-icon="inline-start" />
                ) : (
                    <ShieldCheck data-icon="inline-start" />
                )}
                Check with LHDN
            </Button>
            {idValue.trim() === '' && (
                <p className="text-sm text-muted-foreground">
                    Enter the ID number to check the tax number with LHDN.
                </p>
            )}
            {failed && (
                <p className="text-sm text-destructive">
                    The check did not go through. Try again.
                </p>
            )}
            {current && (
                <Alert
                    variant={
                        current.result === 'match' ? 'default' : 'destructive'
                    }
                >
                    {current.result === 'match' ? (
                        <CircleCheck className="text-success" />
                    ) : (
                        <CircleAlert />
                    )}
                    <AlertDescription className="flex flex-col gap-2">
                        <p>{current.message}</p>
                        {current.result === 'found' && current.tin && (
                            <Button
                                type="button"
                                size="sm"
                                className="self-start"
                                onClick={() => onUse(current.tin!)}
                            >
                                Use {current.tin}
                            </Button>
                        )}
                    </AlertDescription>
                </Alert>
            )}
        </div>
    );
}

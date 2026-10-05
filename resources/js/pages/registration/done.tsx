import { Head, Link } from '@inertiajs/react';
import { CircleCheck } from 'lucide-react';
import { buttonVariants } from '@/components/ui/button';
import { guest, show } from '@/routes/registration';

type Done = App.Data.Registration.RegistrationCardDoneData;

/** After a guest signs. It names only the next guest, so the last guest's details do not stay on the screen. */
export default function RegistrationDonePage({ done }: { done: Done }) {
    const word = (text: string) => done.labels[text] ?? text;
    const next =
        done.nextUnitGuestId !== null && done.nextGuestName !== null
            ? word('Next: :name (:position of :total)')
                  .replace(':name', done.nextGuestName)
                  .replace(':position', String(done.nextPosition ?? ''))
                  .replace(':total', String(done.total))
            : null;

    return (
        <>
            <Head title={word('Thank you.')} />
            <div className="flex flex-1 flex-col items-center justify-center gap-4 text-center">
                <CircleCheck className="size-12 text-success" />
                <h1 className="font-heading text-2xl font-semibold">
                    {word('Thank you.')}
                </h1>
                {next !== null && done.nextUnitGuestId !== null ? (
                    <>
                        <p className="text-muted-foreground">{next}</p>
                        <Link
                            href={guest.url(
                                { unitGuest: done.nextUnitGuestId },
                                { query: { locale: done.locale } },
                            )}
                            className={buttonVariants({ size: 'lg' })}
                        >
                            {word('Continue')}
                        </Link>
                    </>
                ) : (
                    <p className="text-muted-foreground">
                        {word(
                            'All done. Please return the device to the front desk.',
                        )}
                    </p>
                )}
            </div>
            <Link
                href={show.url({ unitGuest: done.unitGuestId })}
                className="self-center text-xs text-muted-foreground underline-offset-4 hover:underline"
            >
                {word('Staff: back to desk')}
            </Link>
        </>
    );
}

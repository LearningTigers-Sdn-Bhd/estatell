import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { waiting as waitingRoute } from '@/routes/onboarding';

export default function Waiting({ ownerName }: { ownerName: string | null }) {
    return (
        <>
            <Head title="Property being set up" />
            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title="Property being set up"
                    description={
                        ownerName
                            ? `Your property is still being set up. Ask ${ownerName}.`
                            : 'Your property is still being set up. Ask the property owner.'
                    }
                />
            </div>
        </>
    );
}

Waiting.layout = {
    breadcrumbs: [{ title: 'Property being set up', href: waitingRoute() }],
};

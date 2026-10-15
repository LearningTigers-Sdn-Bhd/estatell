import { Head, setLayoutProps } from '@inertiajs/react';

export default function Waiting({ ownerName }: { ownerName: string | null }) {
    setLayoutProps({
        title: 'Property being set up',
        description: ownerName
            ? `Your property is still being set up. Ask ${ownerName}.`
            : 'Your property is still being set up. Ask the property owner.',
    });

    return <Head title="Property being set up" />;
}

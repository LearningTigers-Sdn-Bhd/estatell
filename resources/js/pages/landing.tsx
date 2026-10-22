import { Head } from '@inertiajs/react';
import { useEffect } from 'react';
import { Channels } from '@/components/landing/channels';
import { CheckinModules } from '@/components/landing/checkin-modules';
import { Closing } from '@/components/landing/closing';
import { Concierge } from '@/components/landing/concierge';
import { Hero } from '@/components/landing/hero';
import { Navbar } from '@/components/landing/navbar';
import { PlansSteps } from '@/components/landing/plans-steps';
import { Platform } from '@/components/landing/platform';
import { PricingEngine } from '@/components/landing/pricing-engine';
import { SavingsCalculator } from '@/components/landing/savings-calculator';

const description =
    'PMS, Channel Manager and Booking Engine in one system. AI, automation and self-service layered on top to run your hotel end to end.';

export default function Landing() {
    // Nav links and the logo scroll smoothly on this page only; reduced motion keeps the instant jump.
    useEffect(() => {
        const classes = ['scroll-smooth', 'motion-reduce:scroll-auto'];

        document.documentElement.classList.add(...classes);
        // Fetch the footer logo font now, so it is ready when the footer scrolls into view.
        void document.fonts.load('400 1em "Archivo Black"');

        return () => document.documentElement.classList.remove(...classes);
    }, []);

    return (
        <>
            <Head title="The Hotel Operating Core">
                <meta name="description" content={description} />
                <meta
                    property="og:title"
                    content="Estatell — The Hotel Operating Core"
                />
                <meta property="og:description" content={description} />
            </Head>
            <Navbar />
            <main id="top" tabIndex={-1}>
                <Hero />
                <Platform />
                <Channels />
                <Concierge />
                <SavingsCalculator />
                <PricingEngine />
                <CheckinModules />
                <PlansSteps />
                <Closing />
            </main>
        </>
    );
}

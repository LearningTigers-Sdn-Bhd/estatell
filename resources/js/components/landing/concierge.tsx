import { MessageCircle, Phone, Plus, Pause, Play } from 'lucide-react';
import { Container } from './ui';
import { useScrollReveal } from './use-reveal';
import { usePreviewPlayback } from './use-preview-playback';

const features = [
    {
        title: 'Guest engagement',
        body: 'Pre-stay reminder, pre-check-in message, welcome on check-in day, mid-stay check-in, checkout reminder, post-stay review request and a loyalty offer to bring guests back.',
        messages: [
            {
                from: 'Hotel',
                text: 'Hi Sarah, your stay starts tomorrow. Complete pre-check-in before you arrive and spend less time at the desk.',
            },
            { from: 'Guest', text: 'All done. Looking forward to our stay!' },
            {
                from: 'Hotel',
                text: 'We look forward to welcoming you. Check-in starts at 3 pm.',
            },
        ],
    },
    {
        title: 'AI Concierge Q&A',
        body: 'Wi-Fi, room facilities, parking availability, hotel policies, check-in times and safety answers, instantly, at any hour.',
        messages: [
            {
                from: 'Guest',
                text: 'Hi! Is there parking, and what time is breakfast?',
            },
            {
                from: 'Hotel',
                text: 'Guest parking is beside the lobby. Breakfast is served from 7 to 10 am in the ground-floor restaurant.',
            },
            {
                from: 'Guest',
                text: 'Perfect, thank you. That’s everything we needed.',
            },
        ],
    },
    {
        title: 'AI voice calls on WhatsApp',
        soon: true,
        body: 'Guests call on WhatsApp and speak to the AI Concierge, which answers questions and guides them to book a room.',
        messages: [
            {
                from: 'Guest',
                text: 'I’m calling to ask about a room for two this weekend.',
            },
            {
                from: 'Hotel',
                text: 'I can help with that. What dates would you like to stay?',
            },
            { from: 'Guest', text: 'Friday to Sunday, for two adults.' },
        ],
    },
    {
        title: 'Housekeeping',
        body: 'Guests request items in chat. Hotels track delivery and fulfillment in real time.',
        messages: [
            {
                from: 'Guest',
                text: 'Could we have two extra towels in room 204, please?',
            },
            {
                from: 'Hotel',
                text: 'Of course. I’ve sent your request to housekeeping. You can follow its status here.',
            },
            {
                from: 'Hotel',
                text: 'Housekeeping has marked your towels as delivered. Anything else you need?',
            },
        ],
    },
    {
        title: 'Complaint resolution',
        body: 'Issues are logged, routed to the right team, and guests see the resolution status. The owner sees a report of all complaints.',
        messages: [
            {
                from: 'Guest',
                text: 'The air conditioning in room 204 isn’t cooling properly.',
            },
            {
                from: 'Hotel',
                text: 'I’m sorry about that. I’ve logged the issue and alerted our maintenance team.',
            },
            {
                from: 'Hotel',
                text: 'Maintenance has marked the issue as resolved. Is the room comfortable now?',
            },
        ],
    },
    {
        title: 'Activities & offers',
        body: 'Push restaurant dining specials, special occasion packages, spa slots and other offers through the chat.',
        messages: [
            {
                from: 'Hotel',
                text: 'Planning a quiet evening? Our restaurant has a sunset dining special tonight. Would you like the details?',
            },
            { from: 'Guest', text: 'Yes, please! Is there an option for two?' },
            {
                from: 'Hotel',
                text: 'There is a dinner-for-two package. I can share the menu and help you request a table.',
            },
        ],
    },
];

export function Concierge() {
    const copyRef = useScrollReveal();
    const chatRef = useScrollReveal(120);
    const {
        sectionRef,
        selected,
        paused,
        reducedMotion,
        manual,
        setFocused,
        select,
        togglePaused,
    } = usePreviewPlayback(features.length);
    const active = features[selected];

    return (
        <section
            ref={sectionRef}
            onPointerDownCapture={() => setFocused(false)}
            onKeyDownCapture={() => setFocused(true)}
            onFocusCapture={(event) =>
                setFocused(event.target.matches(':focus-visible'))
            }
            onBlurCapture={(event) => {
                if (!event.currentTarget.contains(event.relatedTarget))
                    setFocused(false);
            }}
            id="concierge"
            aria-labelledby="concierge-heading"
            className="dark scroll-mt-24 bg-card py-10 text-foreground sm:py-14 lg:py-16"
        >
            <Container>
                <div ref={copyRef}>
                    <p className="text-xs font-medium tracking-widest text-chart-1 uppercase">
                        AI Concierge
                    </p>
                    <h2
                        id="concierge-heading"
                        className="mt-5 text-4xl leading-none font-medium tracking-tighter sm:text-5xl xl:text-6xl"
                    >
                        Less busywork.
                        <br />
                        <span className="text-chart-1">More welcome.</span>
                    </h2>
                </div>

                <div
                    ref={chatRef}
                    className="mt-10 grid items-start gap-8 border-t border-border pt-8 sm:mt-16 sm:gap-12 sm:pt-10 lg:grid-cols-[1.1fr_0.9fr] lg:gap-20"
                >
                    <div
                        id="concierge-preview"
                        role="region"
                        aria-label="Concierge scenario preview"
                        aria-live={manual ? 'polite' : 'off'}
                        aria-atomic="true"
                        className="min-w-0"
                    >
                        <div data-preview-content>
                            <div className="flex items-center gap-3">
                                <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-chart-1/10 text-chart-1">
                                    {active.soon ? (
                                        <Phone
                                            className="size-5"
                                            aria-hidden="true"
                                        />
                                    ) : (
                                        <MessageCircle
                                            className="size-5"
                                            aria-hidden="true"
                                        />
                                    )}
                                </span>
                                <div className="min-w-0">
                                    <h3 className="text-sm font-medium">
                                        {active.title}
                                    </h3>
                                    <p className="mt-1 text-xs text-foreground/75">
                                        {active.soon
                                            ? 'Coming soon · Illustrative voice-call transcript'
                                            : 'Hotel Borneo Bay · Illustrative conversation'}
                                    </p>
                                </div>
                            </div>
                            <ol
                                className="grid gap-5 py-6 sm:min-h-[350px]"
                                aria-label={
                                    active.soon
                                        ? 'Sample voice-call transcript'
                                        : 'Sample guest conversation'
                                }
                            >
                                {active.messages.map((message, index) => (
                                    <li
                                        key={index}
                                        className={`max-w-[90%] sm:max-w-[82%] ${message.from === 'Guest' ? 'ml-auto' : 'mr-auto'}`}
                                    >
                                        <p
                                            className={`mb-2 text-xs text-foreground/75 ${message.from === 'Guest' ? 'text-right' : ''}`}
                                        >
                                            {message.from === 'Guest'
                                                ? 'Guest'
                                                : 'AI Concierge'}
                                        </p>
                                        <p
                                            className={`rounded-2xl px-4 py-3 text-sm leading-relaxed sm:px-5 sm:py-4 ${message.from === 'Guest' ? 'rounded-tr-sm border border-chart-1/20 bg-muted text-foreground' : 'rounded-tl-sm bg-foreground text-background'}`}
                                        >
                                            {message.text}
                                        </p>
                                    </li>
                                ))}
                            </ol>
                            <p className="text-xs leading-relaxed text-foreground/75">
                                One conversation, from the first hello to the
                                next stay.
                            </p>
                        </div>
                    </div>
                    <div>
                        <p className="mb-4 text-xs font-medium tracking-widest text-foreground/75 uppercase">
                            A little help, all stay long
                        </p>
                        {features.map((feature, index) => (
                            <div
                                key={feature.title}
                                className="border-b border-border"
                            >
                                <h3>
                                    <button
                                        type="button"
                                        id={`concierge-feature-${index}`}
                                        aria-expanded={selected === index}
                                        aria-controls={`concierge-description-${index} concierge-preview`}
                                        onClick={() => select(index)}
                                        className={`flex min-h-16 w-full cursor-pointer items-center justify-between gap-4 py-5 text-left text-base font-medium transition-colors hover:text-chart-1 motion-reduce:transition-none ${selected === index ? 'text-chart-1' : 'text-foreground'}`}
                                    >
                                        <span className="flex flex-wrap items-center gap-x-3 gap-y-2">
                                            {feature.title}
                                            {feature.soon && (
                                                <span className="rounded-full border border-border px-2 py-1 text-xs font-normal tracking-wider text-foreground/75 uppercase">
                                                    Coming soon
                                                </span>
                                            )}
                                        </span>
                                        <Plus
                                            className={`size-4 shrink-0 transition-transform motion-reduce:transition-none ${selected === index ? 'rotate-45' : ''}`}
                                            strokeWidth={1.5}
                                            aria-hidden="true"
                                        />
                                    </button>
                                </h3>
                                <div
                                    id={`concierge-description-${index}`}
                                    hidden={selected !== index}
                                    aria-labelledby={`concierge-feature-${index}`}
                                >
                                    <p className="pr-7 pb-6 text-sm leading-relaxed text-foreground/75">
                                        {feature.body}
                                    </p>
                                </div>
                            </div>
                        ))}
                        {!reducedMotion && (
                            <button
                                type="button"
                                onClick={togglePaused}
                                className="mt-5 inline-flex min-h-11 items-center gap-2 text-xs font-medium text-foreground/75 hover:text-chart-1"
                                aria-label={
                                    paused
                                        ? 'Play concierge preview'
                                        : 'Pause concierge preview'
                                }
                            >
                                {paused ? (
                                    <Play
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                ) : (
                                    <Pause
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                )}
                                {paused ? 'Play preview' : 'Pause autoplay'}
                            </button>
                        )}
                    </div>
                </div>
            </Container>
        </section>
    );
}

import data from './services.json';

// The copy for the /services/* pages lives in services.json. PHP reads the same
// file for the routes, sitemap.xml and llms.txt (app/Services/Landing/ServicePages.php),
// so adding a service means adding one entry there and nothing else.
// Facts come from the landing page and docs/. No statistics or customer claims.

// What sits beside the headline. Photos are existing landing images; the rest are drawn in service-visuals.tsx.
export type Visual =
    | { type: 'photo'; src: string; alt: string; width: number; height: number }
    | { type: 'pin' }
    | { type: 'chat' }
    | { type: 'sync' };

export type Service = {
    visual: Visual;
    slug: string;
    name: string;
    metaTitle: string;
    description: string;
    headline: [string, string];
    answer: string;
    featuresTitle: string;
    features: { title: string; body: string }[];
    flow?: { title: string; steps: { title: string; body: string }[] };
    catalogue?: {
        title: string;
        intro: string;
        groups: {
            title: string;
            models: { name: string; image: string }[];
        }[];
    };
    faqs: { q: string; a: string }[];
    blurb: string;
};

export const services = data as Service[];

export const findService = (slug: string) =>
    services.find((service) => service.slug === slug);

import { findService } from '@/components/landing/services';
import { ServicePage } from '@/components/landing/service-page';

// The route only matches known slugs (routes/web.php), so the lookup always hits.
export default function ServiceShow({ slug }: { slug: string }) {
    const service = findService(slug);

    return service ? <ServicePage service={service} /> : null;
}

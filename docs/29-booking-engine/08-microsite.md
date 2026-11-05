# Microsite

## Purpose

Give a property a booking website with no build or deployment per property. It is not a drag-and-drop builder: a property picks a template and replaces its images.

## Repo

`estatell-booking-microsite`, a separate repo, created when this item is built. Astro with React parts, one Node app on Coolify, using on-demand rendering and the Node standalone adapter. To run it against a local estatell, see [Local development](10-local-dev-microsite.md).

```
estatell-booking-microsite/
├── src/
│   ├── pages/[slug]/     home, browse, book, manage booking
│   ├── pages/api/        server routes that add the API token
│   ├── templates/        island-resort, city-hotel, custom designs
│   ├── booking/          the booking flow's hooks and default screens
│   ├── components/ui/    shared parts, in shadcn's style
│   └── lib/estatell.ts   the one API client
├── astro.config.mjs
└── Dockerfile
```

| Part          | Shared by all sites?      | What it is for                               |
| ------------- | ------------------------- | -------------------------------------------- |
| `templates/`  | No, one folder per design | How the pages and booking screens look       |
| `booking/`    | Yes                       | The rules of booking, and default screens    |
| `components/` | Yes                       | Building blocks, which templates can restyle |
| `lib/`        | Yes                       | The only code that talks to estatell         |

Standard and custom templates have the same abilities: each can replace any page or booking screen. Every template uses the same booking flow, so one fix to the rules reaches every site. They differ only in who can pick them.

## How a page loads

```
Guest opens booking.estatell.com/sand-bay
        │
        ▼
Microsite app (one deployment)
        │  1. GET /api/booking/v1/sites/sand-bay  (the token stays on the server)
        ▼
estatell returns: code ES1142, template island-resort, images, colours
        │
        │  2. Loads the island-resort template
        │  3. Fetches content from the API
        ▼
Guest sees Sand Bay's site
```

- Astro pages send almost no JavaScript. The date picker, search, checkout, payment status and Manage booking are React parts.
- React parts call the microsite's own `pages/api/` routes, which add the secret token. The browser never calls estatell.
- Content and site settings are cached for a few minutes. Prices and availability are never cached.

## Page security

The payment page is the main target. A script injected into it cannot fake a booking or change the amount estatell charges, because only the signed webhook creates a booking and estatell sets the amount. It can still steal guest details, show a fake card form, or open checkout with an attacker's key so the guest pays the attacker.

| Rule               | Detail                                                                                                                                                                            |
| ------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Escape all content | Hotel text stays escaped. Do not use `set:html` or `dangerouslySetInnerHTML` on it. Sanitise on the server if it ever needs formatting                                            |
| Content policy     | A strict Content Security Policy allows scripts only from the microsite and `checkout.razorpay.com`. Test Curlec's other domains                                                  |
| Payment step       | No analytics, chat or other third-party scripts, including in custom designs                                                                                                      |
| Return address     | Send guests only to the microsite or an approved owner address                                                                                                                    |
| Hold token         | The token arrives in the [return address](04-payment.md#guest-return), `/{slug}/book/{token}`. Decide how the browser stores it, because a script on the page can read and use it |

## Template registry

Admin › Booking website templates (`/admin/site-templates`, `admin.site-templates.*`). Writes need `support.2fa`.

```
site_templates        id, key (unique), name, type (SiteTemplateType),
                      property_id (nullable), status (SiteTemplateStatus),
                      allows_main_color (bool), preview_url (nullable), timestamps
                      CHECK: a Custom template has a property; a Standard one has none
site_template_slots   id, site_template_id, key, label, required, position, timestamps
                      unique (site_template_id, key)
```

- `SiteTemplateType`: Standard, Custom. `SiteTemplateStatus`: Draft, Available, Retired.
- Status moves: Draft to Available; Available to Draft or Retired; Retired to Available.
- The key, type and property are set when the template is added and never change, because sites use them. An admin can change the name, preview link and slots.
- `allows_main_color` says whether the Design tab offers the main colour. When it is off, a saved colour is cleared. The seeded templates allow it.
- `preview_url` is where an admin checks a Draft before making it Available. The microsite repo does not exist yet, so it is a plain link.
- `SiteTemplateSeeder` adds `island-resort` and `city-hotel` as Draft Standard templates, each with a required Hero image slot and optional Logo, About image and Icon slots. The Icon is the small image in the browser tab, and a site with none uses the plain estatell icon from the microsite. The demo seeder runs it. On production an admin runs it once, or adds the rows by hand.
- Each template sets its own slots, because its code decides which images it can show. There are no system default slots.
- Removing a slot from a template leaves the uploaded images in storage. They show again if the key comes back.

```
Developer adds template code to the microsite repo and deploys
        │
        ▼
Admin adds it to the registry as Draft and previews it
        │
        ▼
Admin marks it Available
        │
        ├── Standard ─► every Microsite property can pick it
        └── Custom ───► only the assigned property can pick it
                               │
                               ▼
                 Pick it, add images, complete checklist
                               │
                               ▼
                 Publish (anyone with the permission)
```

| Rule              | Detail                                                                             |
| ----------------- | ---------------------------------------------------------------------------------- |
| Template key      | Must match a template folder in the microsite repo                                 |
| Retiring          | It leaves the list for new picks, and sites already using it keep working          |
| Changing template | Images in matching slots carry over, and the checklist flags any new required slot |
| Missing code      | A published site shows a plain default template, not an error page                 |

| Task                                 | Microsite deploy? | Estatell deploy?                     |
| ------------------------------------ | ----------------- | ------------------------------------ |
| New property on an existing template | No                | No                                   |
| Change images or text                | No                | No                                   |
| New template or custom design        | Yes               | No, an admin adds it to the registry |

A quotation for a custom design should include a yearly maintenance fee, because each one adds code to the shared repo.

## SEO

Pages render on the server, so Google reads finished HTML and never calls the API.

| Item                                    | Purpose                                                                       |
| --------------------------------------- | ----------------------------------------------------------------------------- |
| Page title and description per property | What shows in Google results                                                  |
| Hotel structured data                   | Tells Google the name, address and photos                                     |
| Sitemap                                 | Lists every published property's pages, from `GET /api/booking/v1/properties` |
| Real 404 page                           | For an unknown, unpublished or flag-off slug                                  |
| No-index on checkout and manage booking | Keeps private steps out of Google                                             |
| Content cache and compressed images     | Keeps pages fast on mobile                                                    |

A microsite will probably rank for its own hotel name, and is unlikely to rank for broad searches. All properties share one domain. A property with its own website should link to the microsite as its "Book now" page.

## Design for mobile

About 61% of online bookings were on mobile in 2025, and mobile converts lower than desktop (vendor figures). Build every page for a phone first.

## API types

The microsite does not share Wayfinder or generated types with estatell. Keep types in `lib/estatell.ts` to start, and generate them from an API spec if they drift.

Sources: [Astro on-demand rendering](https://docs.astro.build/en/guides/on-demand-rendering/), [Astro environment variables](https://docs.astro.build/en/guides/environment-variables/), [Roomstay benchmarks](https://www.roomstay.io/blog/optimising-hotel-average-conversion-rate).

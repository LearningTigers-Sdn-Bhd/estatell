---
paths:
  - 'routes/**'
---

# Routes

## Route groups, names and files
One route file per module, required from `routes/web.php`. URL prefix, name prefix, page folder and `pms/{feature}` folder use the same word (e.g. `front-desk`). Property configuration lives under `/p/{code}/settings` (`settings.*`); personal pages under `/account` (`account.*`). Run `php artisan wayfinder:generate --with-form` after changes. See docs/routing.md.

## Authorize endpoints in route middleware
Put coarse permission checks in can: middleware on the narrowest route or route group. Do not repeat the same permission check in controllers or Form Requests.

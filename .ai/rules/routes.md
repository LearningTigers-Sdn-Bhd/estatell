---
paths:
  - 'routes/**'
---

# Routes

## Route groups, names and files
One route file per module, required from `routes/web.php`. URL prefix, name prefix, page folder and `pms/{feature}` folder use the same word (e.g. `front-desk`). Property configuration lives under `/p/{code}/settings` (`settings.*`); personal pages under `/account` (`account.*`). Run `php artisan wayfinder:generate --with-form` after changes. See docs/routing.md.

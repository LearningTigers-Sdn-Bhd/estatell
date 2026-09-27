---
paths:
  - 'resources/js/pages/admin/**'
---

# Admin

## Keep admin write controls visible without 2FA
Do not hide or disable admin write controls based on `adminNeedsTwoFactor`. The `admin.2fa` route middleware enforces 2FA and flashes "Two-factor required for this action.", and the admin banner explains it. Every admin write route carries `admin.2fa`.

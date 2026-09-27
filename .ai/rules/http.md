---
paths:
  - 'app/Http/**'
---

# Http

## Thin controllers and property access
A controller method does: Form Request validation, one Action or Service call, one Inertia response or redirect. Staff routes sit under `/p/{property:code}` behind `EnsurePropertyAccess` (`property.access`), which resolves the property, sets the permission team, URL defaults and Pennant scope. Policies check the permission and that `property_id` matches. See docs/routing.md.

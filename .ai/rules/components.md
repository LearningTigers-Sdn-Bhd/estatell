---
paths:
  - 'resources/js/components/**'
---

# Components

## Component folders and import direction
`components/ui` = shadcn only. `components/pms` root = shared PMS components used by 2+ modules. `components/pms/{engine}` = shared engines (`grid/`). `components/pms/{feature}` = components used only by one domain-heavy page (`stay-view/`, `folio/`). Imports flow pages → pms/{feature} → pms root → ui; feature folders never import each other (promote to root instead). No business logic in components. See docs/design-system/03-component-folders.md.

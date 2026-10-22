---
paths:
  - 'resources/js/components/landing/**'
---

# Landing

## Landing page: follow DESIGN.md first, arbitrary values as fallback
The public landing page (`pages/landing.tsx`, `components/landing/`) follows DESIGN.md: semantic color tokens only, standard Tailwind utilities, no GSAP or other new dependency without approval.
Try the standard utility scale first. Only if a section looks clearly wrong, allow arbitrary layout values (clamp sizes, cqw, safe-area padding) inside `components/landing/` for that spot. Colors never use arbitrary values or palette classes.
Record each fallback spot in `docs/design-system/` so the exception stays small.

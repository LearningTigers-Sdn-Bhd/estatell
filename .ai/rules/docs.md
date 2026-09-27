---
paths:
  - 'docs/**'
---

# Docs

## Docs structure and upkeep
Module docs live in `docs/{NN-module}/{NN-feature}.md`, numbered in build order; design-system parts in `docs/design-system/`. Update the matching doc in the same change when behavior, schema or a decision changes. Plain English, short sentences, tables for comparisons, no filler. Keep files small so agents can read one part. Run `npx vp fmt` because `npm run check` also checks Markdown.

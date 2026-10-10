# Design system: Form containers

Part of the [design system](../design-system.md). Short contract: [DESIGN.md](../../DESIGN.md).

## Counting fields

A field is any input the user can change, counted one by one. An address block with 5 inputs counts as 5. A switch counts as 1. Read-only summaries do not count.

## Choosing the container

| Container              | Fields                | Sections | Other limits                                                      |
| ---------------------- | --------------------- | -------- | ----------------------------------------------------------------- |
| **AlertDialog**        | 0–1 (optional reason) | –        | Destructive or irreversible confirmations                         |
| **Dialog**             | 1–5                   | 1        | Fits without scrolling at 720 px viewport height. One decision.   |
| **Sheet** (right side) | 4–12                  | 1–2      | The page behind must stay visible for context. Scrolling allowed. |
| **Full page**          | 13+                   | 3+       | Everything else                                                   |

For 4–5 fields: use a Dialog when opened from a detail page, and a Sheet when opened from a grid or board (Stay View, housekeeping) where the context behind matters.

## Always a full page, whatever the count

1. Repeating rows (unit lines, age bands, inclusions).
2. File uploads or media (photos). The ID scan step inside the registration card is part of that full page. Exception: a small form of up to 12 fields in one section whose only uploads are one or two documents, such as the guest ID document form, may use a Sheet.
3. Multiple steps (wizard, registration card).
4. Needs its own URL (share, back button, bookmark).
5. Long editing sessions (terms editor, rate plan setup).

## Hard rules

- **One overlay level.** A Combobox or DatePicker popover inside a Dialog is fine. A Dialog opening another Dialog is not; use a full page. **One exception:** the Night Audit sheet may open the check-in, check-in-all and check-out Dialogs of the reservation page over itself (`AuditDialogHost`), because the sheet is a worklist and the hotelier would otherwise leave it for each fix. No other overlay does this.
- Overlays have **no URL**. If it must be linkable, it is a full page.
- Sheets and full pages with **unsaved changes** ask for confirmation before closing or leaving.

## Sizes

| Container      | Sizes                                                                                                                   |
| -------------- | ----------------------------------------------------------------------------------------------------------------------- |
| Dialog         | `sm` 400 px (1–2 fields), `md` 512 px (3–5 fields, default)                                                             |
| Sheet          | `md` 480 px (default), `lg` 640 px (two sections or a table inside)                                                     |
| Full page form | Content column max 720 px, one column. Two columns only for short paired fields (City + Postcode, Min stay + Max stay). |

One exception: the [Move charges](../13-folio/07-move-charges.md) page uses the full width in two columns, **From** and **To**, because it is a worklist that shows a move before it happens. The columns stack on a narrow screen. No other full page uses two columns.

## Behavior

- Submit with Inertia `<Form>` or `useForm` and a Form Request.
- On success: close the overlay, show a `toast`, reload only the affected props (`only: [...]`).
- On error: show errors inline through `FormField`, move focus to the first invalid field, keep the overlay open.
- Dialog actions are right-aligned. Sheets and full pages use a sticky `FormActions` footer.
- The primary button label is a verb and object: "Record payment", not "Submit".

## Mobile (under 768 px)

- A Dialog becomes full width, anchored to the bottom.
- A Sheet becomes full screen.
- A full page stays one column with the sticky footer.
- `FormOverlay` owns this behavior. Pages never handle it. It uses responsive Dialog and Sheet classes, not the Drawer: a Drawer adds swipe gestures that a form does not need.

## FormOverlay

One shell for every Dialog and Sheet form (`components/pms/form-overlay.tsx`). The page keeps `useForm`, the route and `onSuccess`. `FormOverlay` never submits anything.

| Prop                                                      | Meaning                                                                                                                                                                                              |
| --------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `variant`, `size`                                         | `dialog` with `sm` or `md`, or `sheet` with `md` or `lg`. A pair that does not exist is a type error.                                                                                                |
| `open`, `onOpenChange`                                    | Controlled by the page.                                                                                                                                                                              |
| `title`, `description`                                    | The header.                                                                                                                                                                                          |
| `errors`                                                  | The form's errors. `errors.form` shows as `FormError` at the top.                                                                                                                                    |
| `dirty`                                                   | Sheet only. A dirty Sheet asks before it closes.                                                                                                                                                     |
| `discardTitle`                                            | The title of that confirm, for example "Discard this unit?".                                                                                                                                         |
| `onSubmit`, `submitLabel`, `processing`, `submitDisabled` | The primary button: a verb and object, a spinner while processing, still focusable when disabled.                                                                                                    |
| `submitVariant`                                           | `default`, or `destructive` for a form that undoes something, such as reversing an allocation.                                                                                                       |
| `footer`                                                  | Optional. Replaces the Cancel and primary buttons for a form with more than one step (apply discount: review, then apply). The overlay still owns the footer container, its layout and its position. |
| `loading`                                                 | Shows skeleton fields while an optional prop loads.                                                                                                                                                  |
| `children`                                                | The fields only.                                                                                                                                                                                     |

It owns:

- The header, a scrolling field group and the `<form>` element.
- The footer: right-aligned **Cancel** and the primary button in a Dialog, the same two in a sticky footer in a Sheet.
- The discard confirm (`DiscardChangesDialog`) when a dirty Sheet closes by Esc, the close button, **Cancel** or an outside click. A Dialog never asks, because it has 1–5 fields.
- Focus on the first invalid field after a failed submit.
- The mobile layout above.

Tailwind has no 400 px or 480 px step, so the sizes use the spacing scale (`max-w-100` for Dialog `sm`, `max-w-120` for Sheet `md`, `max-w-160` for Sheet `lg`).

`useFreshOnOpen(open)` (`components/pms/use-fresh-on-open.ts`) returns a key that changes each time the overlay opens. The page puts `useForm` in an inner component and gives it that key, so a reopened overlay has no old values or errors and the exit animation is not cut short.

A page that opens an overlay for a row (`row !== null`) passes the row through `useRetainedValue` (`components/pms/use-retained-value.ts`). It keeps the last row while the overlay closes, so the content stays for the exit animation. The inner form gets the row id and `useFreshOnOpen` in its key.

The optional-prop request stays in `useFormOptions`. The page calls it and passes `loading` down.

`FormError`, `DiscardChangesDialog` and `useFormOptions` live in `components/pms/` next to `FormOverlay`. The folio and city-ledger overlays import them from there.

Not for every overlay. These stay as they are: AlertDialogs, read-only previews (print voucher) and a Sheet that is mostly a list (routing rules).

Built (see the [checklist](../checklist.md)) for the five reservation overlays first (Add unit, Attach guest, Add add-on, Edit group, Change company). The folio and city-ledger form overlays use it too. `submitLabel` is a node, so a button can show a live total (record payment).

## Layout examples

Dialog: record payment (3 fields).

```
┌ Record payment ───────────────── ✕ ┐
│ Method   [ Cash │ Card │ Transfer ]│
│ Amount   [ RM │ 480.00           ] │
│ Ref      [ ______________        ] │
│                                    │
│               [ Cancel ] [ Record ]│
└────────────────────────────────────┘
```

Sheet: edit charge item (6 fields), list stays visible.

```
┌ Charge items ──────────────────────┬ Edit charge item ────────── ✕ ┐
│ Breakfast        RM 25  per guest  │ Name        [ Breakfast     ] │
│ Extra bed        RM 60  per night  │ Code        [ F&B ⌄         ] │
│ ▸ Laundry        RM 15  per item   │ Amount      [ RM │ 25.00    ] │
│ Parking          RM 10  per night  │ Unit        [ Per guest-night⌄]│
│                                    │ Override    [● on ]           │
│                                    │ Active      [● on ]           │
│                                    ├───────────────────────────────┤
│                                    │         [ Cancel ]  [ Save ]  │
└────────────────────────────────────┴───────────────────────────────┘
```

Full page: new room type (14 fields in 3 sections, plus photos).

```
Room types / New room type
┌──────────────────────────────── max 720px ───────────┐
│ Basics                                               │
│ Name [__________]   Sell unit [ Room │ Bed ]         │
│ Description [__________________________________]     │
│ Occupancy                                            │
│ Max adults [2]  Max children [1]  Base occupancy [2] │
│ Rules                                                │
│ Smoking [○]  Pets [○]  Terms document [ Default ⌄ ]  │
│ Photos  [ drop files ]                               │
└──────────────────────────────────────────────────────┘
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                                [ Cancel ] [ Create ]
```

## estatell forms by container

| Container   | Forms                                                                                                                                                |
| ----------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| AlertDialog | Void, cancel unit, force close audit                                                                                                                 |
| Dialog      | Invite staff, guest note, tag, attach guest, record payment or refund, post charge, apply discount, guest quick-add, block room (from a detail page) |
| Sheet       | Block room (from Stay View), housekeeping assign and status, transfer folio lines (`lg`), charge item, payment method, tax, discount, meal period    |
| Full page   | Company, guest profile, room type, rate plan, booking form, registration card, terms editor, property settings, onboarding steps                     |

## Unsaved changes

A full-page form whose tabs or links lead to other pages guards its unsaved changes with `useUnsavedChanges(form.isDirty)` and `UnsavedChangesDialog`.

- In-app navigation is blocked while the form is dirty. An AlertDialog offers **Cancel** (stay), **Discard** (leave) and **Save** (save, then leave).
- Prefetches, non-GET requests and visits to the current URL are not blocked. A browser reload or tab close asks the browser to confirm.
- After a successful save the form calls `form.setDefaults()`, so it is clean again.
- Built for the rate plan editor. Use it for any other full-page editor with several tab pages.

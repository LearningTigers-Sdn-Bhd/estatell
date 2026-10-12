# Print, export and layout

## Purpose

Let housekeeping print the board as a work sheet, and let a manager choose its columns once for the whole property. It reuses the shared report frame in [Core reports](../22-reports/01-core-reports.md): the layout, the PDF, the spreadsheet and row selection are the same code as the other reports.

## Decided

- The column layout is **per property**, not per user. A manager sets it in the Layout Sheet and every user sees it.
- The checklist is **on paper**. The sheet prints empty pen boxes. Nothing is ticked in the app and nothing gates Inspected.
- The View toggle (All, Grouped by room group, Grouped by room type) is a board feature. The report frame has no section headers, so the PDF is one list sorted by room group.

## The report

`ReportKey::HousekeepingTasks` (slug `housekeeping-tasks`):

| Part                | Value                                                                               |
| ------------------- | ----------------------------------------------------------------------------------- |
| Source              | `HousekeepingTasksReport` (`app/Services/Reports/`) over `HousekeepingBoardBuilder` |
| Dates               | One date, the open business date                                                    |
| Default orientation | Landscape                                                                           |
| Longest range       | none (one date)                                                                     |
| Default sort        | `due`                                                                               |
| Row                 | `HousekeepingTaskRow`, one for each active room. The `key` is the room id.          |
| Identity numbers    | none, so exports are not logged as identity exports                                 |

- `HousekeepingTaskRow` implements `ReportRow` and is built from `HousekeepingRoomData` (the board page keeps its own Data object, which also holds the actions the viewer may take). The PDF and the spreadsheet read the same rooms, in the same order, because `HousekeepingTasksReport` reads `HousekeepingBoardBuilder`. Its `due` property is the place of the room in that order.
- `HousekeepingTasks` is **not listed in the Reports menu**. `ReportsNavigation` lists every `ReportKey` case under its category, so the key gets a `listedInReportsMenu()` method that returns false for it. The board is opened from Housekeeping in the Front desk context.
- There is no `reports.housekeeping-tasks` page. The shared layout services need the key, and the board is the only page.

## Columns

A merged column keeps its key, label and default visibility. Its split columns are new keys, hidden by default, right after it (see [Core reports](../22-reports/01-core-reports.md#registry)).

| Key                 | Label          | Align | Visible | Split of    | Value type                       |
| ------------------- | -------------- | ----- | ------- | ----------- | -------------------------------- |
| `room`              | Room           | left  | ✓       | –           | text                             |
| `room_group`        | Group          | left  | –       | –           | text                             |
| `unit`              | Type / room    | left  | –       | –           | text                             |
| `room_type`         | Room type      | left  | –       | `unit`      | text                             |
| `status`            | Status         | left  | ✓       | –           | text (label)                     |
| `assigned`          | Assigned       | left  | ✓       | –           | text                             |
| `guest`             | Guest          | left  | ✓       | –           | text                             |
| `arrival`           | Arrival        | left  | ✓       | –           | text (`12 Oct · 14:00`)          |
| `arrival_date`      | Arrival date   | left  | –       | `arrival`   | date                             |
| `arrival_time`      | Arrival time   | left  | –       | `arrival`   | `H:i`                            |
| `departure`         | Departure      | left  | ✓       | –           | text                             |
| `departure_date`    | Departure date | left  | –       | `departure` | date                             |
| `departure_time`    | Departure time | left  | –       | `departure` | `H:i`                            |
| `flags`             | Flags          | left  | ✓       | –           | text (`Priority · DND · Maint.`) |
| `priority`          | Priority       | left  | –       | `flags`     | yes or empty                     |
| `do_not_disturb`    | Do not disturb | left  | –       | `flags`     | yes or empty                     |
| `needs_maintenance` | Maintenance    | left  | –       | `flags`     | yes or empty                     |
| `remarks`           | Remarks        | left  | ✓       | –           | text (remark and reason)         |
| `remark`            | Remark         | left  | –       | `remarks`   | text                             |
| `system_reason`     | Reason         | left  | –       | `remarks`   | text                             |
| `pen_boxes`         | Check          | left  | –       | –           | text (`[ ] BED  [ ] BATH`)       |

- `arrival` and `departure` hold the date and time in one value, so a sort puts them in time order. A same-day turnover keeps both.
- `pen_boxes` comes with the pen box items. Until then the report has 20 columns.
- `room` and `status` stay visible: the Layout Sheet refuses a layout without at least one visible column, and the board needs both.
- `unit` shows "Deluxe / 203"; `room_group` is for properties that use groups.
- `pen_boxes` is **one column** with a stable key. Its cell is built from the checklist items of the property, so no column key depends on data. An Out of service room has an empty cell.
- A same-day turnover shows the departing unit in `departure` and the arriving unit in `arrival`.

## Pen box items

```
housekeeping_checklist_items
  id, property_id (FK, cascade), label varchar(30), code varchar(6), sort_order smallint, timestamps
  unique (property_id, code)
```

- At most **6 items** for each property. The Action refuses the seventh.
- `SeedPropertyDefaults` seeds five: Bed (`BED`), Bathroom (`BATH`), Floor (`FLR`), Towels (`TWL`), Minibar (`MINI`). A migration back-fills existing properties.
- The cell prints each code as `[ ] CODE`. The PDF note (`ReportKey::note()`) prints the legend, for example `BED = Bed · BATH = Bathroom`.
- Labels are plain English text typed by the property. They are not translated.

### Settings page

Settings > Housekeeping (`settings.housekeeping.edit` and `.update`, `/p/{code}/settings/housekeeping`, `housekeeping.manage-checklist`). It lists the items with a label, a code and drag order, with Add item and Remove. `SaveChecklistItems` replaces the list in one transaction and logs `housekeeping.checklist_saved`. It checks the limit and unique codes.

## Layout

- The Columns button on the board opens the shared `ReportLayoutSheet` for `housekeeping-tasks`: order, visibility, alignment, orientation and Preview PDF.
- It shows for users with `settings.manage`. The shared routes `reports.layout.update`, `.destroy` and `.preview` need `reports.view` as well, and Owner and Manager have both.
- The preview posts the `date` field and the server reads the rows again.
- `ReportLayoutResolver` merges the stored layout with the columns in code, so adding a column later needs no data migration.

## Export

Housekeeping staff do not have `reports.view`, and the shared export routes sit behind it. The board therefore has its own thin routes, which call the **same services**:

| Route                                     | Name                       | Guard               | Calls                                                     |
| ----------------------------------------- | -------------------------- | ------------------- | --------------------------------------------------------- |
| `POST /p/{code}/housekeeping/pdf`         | `housekeeping.pdf`         | `housekeeping.view` | `ReportLayoutResolver`, `ReportPage`, `ReportPdf`         |
| `POST /p/{code}/housekeeping/spreadsheet` | `housekeeping.spreadsheet` | `housekeeping.view` | `ReportLayoutResolver`, `ReportPage`, `ReportSpreadsheet` |

- The browser sends the sort and the selected room ids, never row data. The server runs `rows()` again with the saved layout, so an id outside the property is dropped.
- The format is `pdf`, `xlsx` or `csv`. Without selected rows the export holds every room that matches the filters on the screen.
- The PDF has the letterhead and footer from [Printing settings](../02-property/05-printing-settings.md) and uses the layout orientation.
- Rooms are ordered by `due` unless the user sorted a column.

## Sheet for each housekeeper

**Print selected** prints the ticked rooms. To print one housekeeper's sheet, filter **Assigned** to that person and use **Export > Print sheet**. A split in one file (one page for each housekeeper) is a later improvement.

## Out of scope

- Section headers for room groups in the PDF.
- A pen box ticked in the app.
- Several paper sizes. The paper comes from the printing settings.

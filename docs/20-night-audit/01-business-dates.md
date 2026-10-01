# Business dates

## Purpose

All postings belong to a business date, not the calendar date. A 1 AM minibar charge before the audit belongs to yesterday.

## Data model

```
business_dates      id, property_id, date, status (BusinessDateStatus),
                    opened_at, closed_at, closed_by, timestamps
                    unique (property_id, date)
                    partial unique: one current (Open or Auditing) date per property
document_sequences  id, property_id, type (DocumentType), year, last_number, timestamps
                    unique (property_id, type, year)
properties          + document_prefix (3-6 characters A-Z and 0-9, unique)
property_document_prefix_histories
                    id, property_id, prefix (unique), timestamps
```

## Enums

- `BusinessDateStatus`: Open, Auditing, Closed. Open moves to Auditing, Auditing moves to Closed.
- `DocumentType`: Reservation, Folio, Invoice, CreditNote, Receipt, RegistrationCard.

## Services and Actions

- `CurrentBusinessDate`: the Open date, or the Auditing date while the audit runs. Null until go live. Every posting uses it.
- `NextDocumentNumber`: locks the sequence row, increments and returns an `IssuedDocumentNumber` (number, year, reference). It takes an optional `floor`, the highest number already stored, so a stale counter never issues a duplicate. It works inside the caller's transaction, so a document that rolls back gives its number back.
- `DocumentReference`: formats a reference.
- `OpenBusinessDate`: opens the first date of a property. It refuses (returns null) when the property already has any business date. `GoLive` calls it. Later dates are opened by `CloseAndOpenNext`.
- `GenerateDocumentPrefix`: gives a property its prefix. Run when a property is created.

## Document references

Format: `{PREFIX}-{YY}{T}{NNNNN}`, the same as wastays. Example: prefix `GRH`, 2026, receipt, number 42 gives `GRH-26500042`.

| Part     | Rule                                                                                                        |
| -------- | ----------------------------------------------------------------------------------------------------------- |
| `PREFIX` | `properties.document_prefix`.                                                                               |
| `YY`     | The last two digits of the business date year. Before go live, the calendar year in the property time zone. |
| `T`      | One-digit type code (below).                                                                                |
| `NNNNN`  | The number, padded to five digits. A longer number is not cut.                                              |

| Type             | Code |
| ---------------- | ---- |
| Reservation      | 1    |
| RegistrationCard | 2    |
| Folio            | 3    |
| Receipt          | 5    |
| Invoice          | 7    |
| CreditNote       | 8    |

Codes 4 and 6 are unused. wastays used them for the AR invoice and the tourism tax voucher. estatell does not need either: an AR invoice reuses the folio invoice number, and tourism tax is a tax code.

The reference is stored on each document when it is issued. A later prefix change never rewrites issued documents.

## Document prefix

- Built from the property name, as in wastays: initials of three or more words (at most four letters), the first letters of two words, or the start of one word. Padded with `X` to three letters. `EST` when the name has no letters.
- A taken prefix gets a number (`GRH`, `GRH2`, `GRH3`).
- Every prefix is kept in `property_document_prefix_histories`, so a retired prefix is never given to another property.
- It does not change when the property name changes. Editing the prefix (allowed until the first document is issued) comes later.

## Rules

- The first business date is created by `GoLive`, through `OpenBusinessDate`.
- Closed dates are immutable. `PostCharge` rejects them. Corrections post to the current date.
- There is no "undo audit".

## Display

The sidebar always shows the business date next to the system date (property time zone), with the states In sync, Audit pending, Auditing, Overdue and Not live. See [App shell](../design-system/09-app-shell.md#business-date-and-system-date).

Route: `GET /p/{code}/business-date` (`business-date.show`, JSON) returns the same data as the shared `businessDate` prop, or `null` while the property has no current business date.

## Build status

| Part                                                             | Status                                                                          |
| ---------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| Tables, enums, models, services, `OpenBusinessDate`, prefix      | Built.                                                                          |
| Route, shared prop, `BusinessDateIndicator`                      | Built. The "Night audit pending" text becomes a link with the night audit page. |
| `GoLive` calling `OpenBusinessDate`                              | Waits for [Go live](../17-onboarding/03-go-live.md).                            |
| Room blocks moving from `PropertyToday` to `CurrentBusinessDate` | Waits for the first folio posting.                                              |

## Lessons from wastays

Six year + number + reference triplets were spread across `bookings` and `booking_folios`.

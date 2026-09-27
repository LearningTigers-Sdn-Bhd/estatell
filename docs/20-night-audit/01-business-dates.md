# Business dates

## Purpose

All postings belong to a business date, not the calendar date. A 1 AM minibar charge before the audit belongs to yesterday.

## Data model

```
business_dates      id, property_id, date, status (BusinessDateStatus),
                    opened_at, closed_at, closed_by, timestamps
                    partial unique: one Open date per property
document_sequences  id, property_id, type (DocumentType), year, last_number
                    unique (property_id, type, year)
```

## Enums

- `BusinessDateStatus`: Open, Auditing, Closed.
- `DocumentType`: Reservation, Folio, Invoice, CreditNote, Receipt, RegistrationCard.

## Services

- `CurrentBusinessDate`: every posting uses it.
- `NextDocumentNumber`: locks the sequence row and increments.

## Rules

- The first business date is created by `GoLive`.
- Closed dates are immutable. `PostCharge` rejects them. Corrections post to the current date.
- There is no "undo audit".

## Display

The sidebar always shows the business date next to the system date (property time zone), with the states In sync, Audit pending, Auditing, Overdue and Not live. See [App shell](../design-system/09-app-shell.md#business-date-and-system-date).

Route: `GET /p/{code}/business-date` (`business-date.show`, JSON) returns the same data as the shared `businessDate` prop.

## Lessons from wastays

Six year + number + reference triplets were spread across `bookings` and `booking_folios`.

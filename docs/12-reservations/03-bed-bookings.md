# Bed bookings

## Purpose

Sell dorm beds one by one.

## Shape

N beds = N units in one reservation, one occupant per unit.

## Rules

- The booking form shows "Beds: N" for Bed room types and expands into N units.
- Each bed is stored on `unit_nights.bed_id`.
- Each backpacker can check in, move bed, check out or be marked no-show independently.
- With EachPays, each unit gets its own folio.

## Out of scope

Selling a whole dorm as one unit.

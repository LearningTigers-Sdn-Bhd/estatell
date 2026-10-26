# TTLock integration

## Purpose

Give each checked-in stay a PIN for its room door, and take it away when the guest leaves. Staff can also pick the PIN themselves and open a door from the screen. It uses TTLock smart locks through the TTLock Open Platform.

## Scope

| Now                                                                        | Later                                                          |
| -------------------------------------------------------------------------- | -------------------------------------------------------------- |
| TTLock locks (first test lock: Bastn C13), one TTLock account per property | Card and fingerprint registration                              |
| Generated PINs (no gateway needed)                                         | Unlock log import (needs a gateway)                            |
| Custom PINs chosen by staff (gateway needed, alert shown without one)      | Guest self check-in with a PIN sent by email or WhatsApp       |
| Remote unlock from the screen (gateway needed, alert shown without one)    | eKey sharing                                                   |
| PIN issued at check-in, removed at check-out, undo check-in and room move  | A scheduled retry of failed PINs (today staff press **Retry**) |
| Behind the `DoorLocks` feature, off by default                             |                                                                |

## Feature gate

- Pennant feature `DoorLocks` (`door-locks`), off by default, turned on per property by a platform admin like Boats and Accounting.
- When off, the door lock routes return 404, the sidebar item is hidden and no listener does anything.

## Gateway rules

A gateway is a small TTLock device that connects a Bluetooth lock to the internet. Source: TTLock docs, [Passcode](https://euopen.ttlock.com/documentPages/htmlPages/userGuide/passcodeEn.html) and [Gateway](https://euopen.ttlock.com/documentPages/htmlPages/userGuide/gatewayEn.html) guides.

| Action                        | Without a gateway                                                                                                              | With a gateway        |
| ----------------------------- | ------------------------------------------------------------------------------------------------------------------------------ | --------------------- |
| Generated (random) period PIN | Works. TTLock makes it from the lock key. Not stored on the lock.                                                              | Works                 |
| Custom PIN chosen by staff    | Refused with an alert. A cloud-only record would not open the lock.                                                            | Written to the lock   |
| Remote unlock                 | Refused with an alert                                                                                                          | Works                 |
| Take a PIN away early         | Marked revoked here. Staff get a Delete PIN to type on the lock keypad, which invalidates every PIN already used on that lock. | Deleted from the lock |
| Fix the lock clock            | Refused with an alert. Use the TTLock app near the lock.                                                                       | `Set clock`           |

- `door_locks.has_gateway` comes from the TTLock lock list on every sync (`hasGateway`, 1 or 0). It is never typed in by staff.
- Every refused action returns a result, never an HTTP error. The page shows **This needs a gateway installed at the lock**.
- A generated PIN cannot be chosen. It is 6 to 9 digits. A custom PIN is 4 to 9 digits.
- A generated PIN is valid to the hour only, and **must be used once within 24 hours of its start or TTLock suspends it**. Stay PINs start at check-in, so the guest uses them straight away. A PIN issued by hand for a later start can lapse.
- For one lock, one start and end time gives one timed PIN. Two PINs on the same lock need different windows.
- A lock holds a limited number of custom PINs (the docs say 150 in one place and 250 in another). TTLock answers `-3009` when it is full.
- Remote calls go through Bluetooth and can take up to 30 seconds. Our timeout for them is 35 seconds. Remote calls to one lock must run one at a time.
- A lock that refuses remote unlock with `-4043` needs **Remote unlock** switched on in the lock settings of the TTLock app.
- Editing or deleting a generated PIN remotely only works after it has been used once on the lock.

## Flow

```
 1 Flag on       Platform admin turns on Door locks for the property
 2 Connect       Settings > Door locks: sign in with the TTLock app account that owns the locks
 3 Sync          Pull the lock list. Each lock shows battery and whether it has a gateway
 4 Link          Link each lock to a room
 5 Switch on     Issue PINs automatically at check-in
 6 Check-in      CheckInUnit dispatches StayCheckedIn, a listener queues IssueStayPasscodeJob for each linked room
 7 Check-out     CheckOutUnit, UndoCheckIn and MoveUnit dispatch events that queue RevokeStayPasscodeJob
 8 Staff         Passcodes tab: issue a custom PIN, retry, revoke, unlock now
```

## Data model

```
door_lock_connections   id, property_id (unique), ttlock_username, access_token (encrypted), refresh_token (encrypted),
                        token_expires_at, enabled (bool), passcode_mode (PasscodeMode), last_tested_at, last_error, timestamps

door_locks              id, property_id, room_id (nullable), ttlock_lock_id, name, battery_percent (nullable),
                        has_gateway (bool), synced_at, timestamps
                        unique (property_id, ttlock_lock_id), unique (property_id, room_id) where room_id is not null

door_passcodes          id, property_id, door_lock_id, reservation_unit_id (nullable), kind (PasscodeKind),
                        status (PasscodeStatus), code (encrypted, nullable), ttlock_passcode_id (nullable),
                        label, starts_at, ends_at, error (nullable), issued_by (nullable), revoked_at (nullable),
                        idempotency_key (unique), timestamps
```

- The TTLock password is used once to get tokens and is never saved. Tokens are encrypted and never sent to the browser.
- A lock is linked to at most one room, and a room has at most one lock.
- `code` is encrypted because it opens a door. The page shows it to staff with the `settings.manage` permission.
- `idempotency_key` is `stay:{reservation_unit_id}:{door_lock_id}` for automatic PINs, so a repeated job never issues twice.

## Enums

- `PasscodeMode`: Generated, Custom. The default for automatic PINs. Custom falls back to Generated on a lock without a gateway.
- `PasscodeKind`: Generated, Custom.
- `PasscodeStatus`: Pending, Active, Failed, Revoked, Expired. `canTransitionTo()` allows Pending to Active or Failed, Failed to Pending, Active to Revoked or Expired.

## Validity window

`PasscodeWindow` (`app/Services/DoorLocks/`) works out the start and end:

- Start: the check-in moment, or now if the unit checked in later.
- End: `departure_date` at `departure_time` of the unit, in the property time zone.
- A generated period PIN is accurate to the hour: the start is rounded down and the end is rounded up to the hour. A custom PIN keeps its minutes.

### Extending a stay

A generated PIN cannot be edited without a gateway, so a longer stay gets a **new PIN** for the new window.

- `ChangeUnitDates` and `ExtendDepartureTime` dispatch `StayDatesChanged`. The sync checks whether a live PIN of the unit already lasts to the new end. If not, it issues a PIN under the key `stay:{unit}:{lock}:{end timestamp}`. Running it again changes nothing.
- The old PIN is left to run out. It is not revoked, because a Delete PIN typed on the keypad would also remove the new PIN.
- A shorter stay keeps its PIN. Check-out still revokes every PIN of the unit.
- The reservation page shows the newest PIN first and "The stay was extended. Give the guest the newest PIN." while two live PINs share a lock. Staff must pass the new PIN to the guest before the old one ends.
- Still to do for a lock with a gateway: edit the PIN period on the lock so the guest keeps the same PIN. Not verified against the TTLock docs, so it is not built.

## TTLock API

Source: [TTLock Open Platform](https://euopen.ttlock.com/document/doc?urlName=cloud%2Fintroduction.html) Cloud API V3, read from the official doc pages on 2026-10-24 (`/documentPages/htmlPages/cloud/...`). The doc pages show `https://api.sciener.com` (the China site). Overseas accounts use `https://euapi.ttlock.com`: `services.ttlock.base_url` (`TTLOCK_BASE_URL`), default `https://euapi.ttlock.com`. Both answered with the same `invalid client_id` for a dummy id. Every call is a form POST with `clientId`, `accessToken` and `date` (now, in milliseconds; TTLock rejects a `date` more than 5 minutes off with `80000`). Every failure has `errcode`, `errmsg` and `description`.

| Step               | Call                                                                                                                                                                                                                                            |
| ------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Sign in            | `POST /oauth2/token` with `clientId`, `clientSecret`, `username`, `password` (32 char lowercase MD5). The username is the TTLock app account.                                                                                                   |
| Refresh            | `POST /oauth2/token` with `clientId`, `clientSecret`, `grant_type=refresh_token`, `refresh_token`. The access token lasts 90 days, the refresh token 10 years.                                                                                  |
| List locks         | `/v3/lock/list` with `pageNo`, `pageSize` (max 1000). Returns `lockId`, `lockAlias`, `electricQuantity`, `hasGateway`. POST and GET both work.                                                                                                  |
| Generated PIN      | `POST /v3/keyboardPwd/get` with `lockId`, `keyboardPwdType` 3 (timed), `startDate`, `endDate` (hour precision, at most one year). Returns `keyboardPwd`, `keyboardPwdId`.                                                                       |
| Delete PIN         | The same call with `keyboardPwdType` 4. Typed on the keypad it invalidates every PIN already used on the lock.                                                                                                                                  |
| Custom PIN         | `POST /v3/keyboardPwd/add` with `keyboardPwd` (4 to 9 digits), `startDate`, `endDate` (minute precision), `addType` 2 (gateway). Returns `keyboardPwdId`. `addType` 1 means the PIN was already written by Bluetooth, so it is never used here. |
| Delete a PIN       | `POST /v3/keyboardPwd/delete` with `keyboardPwdId`, `deleteType` 2 (gateway)                                                                                                                                                                    |
| Remote unlock      | `POST /v3/lock/unlock` with `lockId`                                                                                                                                                                                                            |
| Set the lock clock | `POST /v3/lock/updateDate` with `lockId`                                                                                                                                                                                                        |

Still to check against the real account: the region host (`euapi.ttlock.com` against `api.sciener.com`), and `hasGateway` for the C13 once a gateway is paired.

## Actions and Jobs

- `SaveDoorLockConnection`: signs in with the username and password, saves the tokens, never the password.
- `TestDoorLockConnection`: reads the lock list and saves `last_tested_at` or `last_error`.
- `SyncDoorLocks`: upserts the lock list. Keeps room links. A lock that vanishes from TTLock is kept, with an old `synced_at`.
- `LinkDoorLock`: links or unlinks a room. Refuses a room of another property.
- `IssueDoorPasscode`: one verb for both kinds. A custom PIN on a lock without a gateway returns a refusal.
- `RevokeDoorPasscode`: deletes through the gateway. Without one it marks the PIN revoked and returns a warning with a Delete PIN to type on the keypad.
- `UnlockDoor`, `AdjustLockClock`: remote unlock and clock fix, gateway only.
- `IssueStayPasscodeJob`, `RevokeStayPasscodeJob`: unique by unit, three tries. An expected failure is saved on the row as Failed.
- Events: `StayCheckedIn`, `StayCheckedOut`, `StayRoomChanged`, `StayDatesChanged`, dispatched after commit by `CheckInUnit`, `CheckOutUnit`, `UndoCheckIn`, `MoveUnit`, `ChangeUnitDates` and `ExtendDepartureTime`. A listener queues the job only when the property has an enabled connection. An error here never changes the stay.

## Revoke note

Without a gateway a revoke cannot delete the PIN from the lock. `RevokeDoorPasscode` saves the warning on the PIN row (`revoke_note`, encrypted, because it can hold a Delete PIN). A manual revoke, and the automatic revoke at check-out, undo check-in or room move, both save it, so staff see it on the PIN row and the reservation page and not only in a toast.

- It shows only while the PIN's window is open (`ends_at` in the future) and the PIN is Revoked.
- Issuing the PIN again for the same stay clears it.
- To verify: how long TTLock keeps a Delete PIN valid. The TTLock docs for `keyboardPwd/get` do not say clearly. The note is saved when the PIN is revoked, so a note read long after may hold a Delete PIN that no longer works.

## Reservation page

The Units tab of a reservation shows a **Door PIN** line on the unit panel (`UnitDoorPins` service, `UnitDoorPinsData`), so front desk can read the PIN out at check-in.

- Shown only when the Door locks feature is on and the user has `front-desk.check-in` or `settings.manage`.
- Shown when the unit has a PIN row, or when it is checked in and a room it sleeps in has a lock. Otherwise the line is hidden.
- Each PIN shows its code, status badge, lock name and valid window. A failed PIN shows its error.
- A checked-in unit whose lock has no live PIN shows "No PIN yet for {lock}" with the reason when known: automatic PINs are off, or the stay has already ended so a PIN would be out of date.
- Revoked PINs stay in the list with the Revoked badge. On a lock without a gateway the row also shows the revoke note (see below) while the PIN's window is open.

## Permissions and routes

`settings.manage` behind the feature gate. Routes are under `/p/{code}/settings/door-locks`: `edit`, `locks`, `passcodes`, `update`, `sync`, `test`, `locks/{doorLock}/link`, `passcodes` (store), `passcodes/{doorPasscode}/revoke`, `passcodes/{doorPasscode}/retry`, `locks/{doorLock}/unlock`, `locks/{doorLock}/clock`.

## Tests

`tests/Feature/DoorLocks/`: the password is not saved and tokens stay out of the page, a sync keeps links, a generated PIN is issued once for a stay (run twice, same row), a custom PIN is refused without a gateway, remote unlock is refused without a gateway, check-out revokes, a revoke without a gateway warns, the feature off returns 404, another property's lock returns 404. `TtlockClientTest` fakes HTTP and covers the token refresh, the form fields, and an `errcode` response.

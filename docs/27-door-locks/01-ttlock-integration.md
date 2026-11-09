# TTLock integration

## Purpose

Give each checked-in stay a PIN for its room door, and take it away when the guest leaves. Staff can also pick the PIN themselves and open a door from the screen. It uses TTLock smart locks through the TTLock Open Platform.

## Scope

| Now                                                                        | Later                                                          |
| -------------------------------------------------------------------------- | -------------------------------------------------------------- |
| TTLock locks (first test lock: Est E13), one TTLock account per property   | Fingerprint registration, a staff screen for cards             |
| Generated PINs (no gateway needed)                                         | Unlock log import (needs a gateway)                            |
| Custom PINs chosen by staff (gateway needed, alert shown without one)      | Guest self check-in with a PIN sent by email or WhatsApp       |
| Remote unlock from the screen (gateway needed, alert shown without one)    | eKey sharing                                                   |
| PIN issued at check-in, removed at check-out, undo check-in and room move  | A scheduled retry of failed PINs (today staff press **Retry**) |
| IC cards registered by UID through a gateway (backend only, no screen yet) |                                                                |
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
                        token_expires_at, enabled (bool), passcode_mode (PasscodeMode), passcode_length (4 to 9, default 6), last_tested_at, last_error, timestamps

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

- `PasscodeMode`: Generated (shown as "Lock-generated"), Custom (shown as "Random PIN via gateway"). The default for automatic PINs. Custom falls back to Generated on a lock without a gateway.
- `PasscodeKind`: Generated, Custom.
- `PasscodeStatus`: Pending, Active, Failed, Revoked, Expired. `canTransitionTo()` allows Pending to Active or Failed, Failed to Pending, Active to Revoked or Expired.

## PIN length and staff PIN

- `passcode_length` (4 to 9, recommended 6) is the length of the random PIN estatell sets in Custom mode. A Generated PIN is made by TTLock (`keyboardPwd/get`, which has no length parameter), so the settings form disables the length in that mode and the stored value is kept.
- `IssueDoorPasscode` makes the random PIN with the length. If TTLock says the PIN already exists on the lock (`errcode` -3007, from the TTLock docs, not yet seen on a real lock) it makes a new random PIN once. A PIN staff typed is never swapped: it fails with "That PIN is already used on this lock. Pick another."
- TTLock refuses PINs it finds too simple (`-2032`). `PinStrength` catches one digit repeated (1111) and runs going up or down by one (1234), which were refused on a real lock; 1122 and 135790 were accepted. `IssueDoorPasscode` refuses a typed PIN like that before calling TTLock, a random PIN is never one, and the Set PIN dialog blocks it. TTLock's exact rule is not published, so an odd PIN can still come back as `-2032` on the PIN row.
- A Generated PIN comes from its hour window, so a window TTLock made a PIN for and that was deleted is refused (`-1026`). That happens when a stay is undone and checked in again within the hour. `IssueDoorPasscode` then starts the window one hour earlier, up to three times, so the PIN works a little before check-in and never after departure. A Generated PIN is always whole hours (09:53 to 09:55 becomes 09:00 to 10:00); a Custom PIN keeps its minutes.
- `MoveDoorPasscodeEnd`: when a stay gets longer or shorter and the live PIN is an Active Custom PIN on a lock with a gateway, `/v3/keyboardPwd/change` (`changeType` 2, with the start date TTLock needs) moves its end, so the guest keeps the same PIN and no second PIN is made. If the PIN is Generated, there is no gateway, or TTLock refuses, a longer stay gets a new PIN under `stay:{unit}:{lock}:{end}` and the old one is left to run out, and a shorter stay keeps its PIN. A Generated PIN is worked out from its window, so it cannot be moved either way, and a shortened stay's Generated PIN lasts to its old end until check-out revokes it. Not yet seen on a real lock for a shorter stay.
- `ReplaceStayPasscode`: on a checked-in unit, staff set a PIN of their own (4 to 9 digits, gateway only). The new PIN is issued first, so a refusal leaves the current PIN as it was. Then every other live PIN of the unit on that lock is revoked, so the guest never has two. The same code as the current PIN changes nothing. The Door PIN line of the reservation page shows a **Set PIN** button on an Active PIN with `canReplace`.
- `ExpireDoorCredentials` (scheduled every five minutes) sets Active PINs and cards past `ends_at` to Expired. The lock already refuses them. On a lock with a gateway it also deletes them from the lock once, so the lock memory does not fill up. A failed delete is not retried: the row is Expired anyway and the dead PIN stays on the lock until it is deleted by hand. Add a `removed_from_lock_at` column and a retry if that fills a lock.
- The staff PIN route also sits behind the `door-locks` feature middleware, like the settings routes.

## Room moves

A PIN goes on the lock of the room the guest sleeps in **now** (`StayLocks::current()`): the room of the latest night dated today or earlier, or of the first night when none has begun. When staff move the unit (`MoveUnit` dispatches `StayRoomChanged`), the sync issues a PIN on the new room's lock and revokes the PIN on the old room's lock, so the guest cannot open a room they have left, which another guest may now sleep in. The guest gets a new PIN, which staff read from the Door PIN line. A PIN is not put on a room the guest has not moved to yet. A move planned for a later date has no event on that date, so its PIN changes at the next sync, not on the day; a scheduled daily sync would fix that and is not built. The kiosk card still covers every room of the stay (`StayLocks::for()`), because a physical card is written once.

## Validity window

`PasscodeWindow` (`app/Services/DoorLocks/`) works out the start and end:

- Start: the check-in moment, or now if the unit checked in later.
- End: `departure_date` at `departure_time` of the unit, in the property time zone.
- A generated period PIN is accurate to the hour: the start is rounded down and the end is rounded up to the hour. A custom PIN keeps its minutes.

### Extending a stay

A generated PIN cannot be edited without a gateway, so a longer stay gets a **new PIN** for the new window.

- `ChangeUnitDates` and `ExtendDepartureTime` dispatch `StayDatesChanged`. The sync checks whether a live PIN of the unit already lasts to the new end. If not, it issues a PIN under the key `stay:{unit}:{lock}:{end timestamp}`. Running it again changes nothing.
- The old PIN is left to run out. It is not revoked, because a Delete PIN typed on the keypad would also remove the new PIN.
- A shorter stay moves a Custom PIN's end earlier through the gateway. A Generated PIN keeps its old end, because it cannot be edited. Check-out revokes every PIN of the unit.
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

Still to check against the real account: the region host (`euapi.ttlock.com` against `api.sciener.com`), and `hasGateway` for the E13 once a gateway is paired.

## IC cards

Backend for the self check-in kiosk (D15 in the kiosk repo). Nothing is written to the card: the kiosk reads its UID and Estatell registers the UID with TTLock. Source: TTLock docs, Add IC card and Delete IC card, read on 2026-10-30.

- **Card number.** The UID bytes in reader order, read as a big-endian decimal (`CardNumber`). UID `0D:D2:5C:E7` is `231890151`, as the TTLock app shows. Four and seven byte UIDs are accepted.
- **Which endpoint.** `/v3/identityCard/add` stores the number as sent. `/v3/identityCard/addForReversedCardNumber` stores it reversed, and TTLock says to use `add` when a card added the other way does not open the lock. We use `add`.
- **Gateway only.** `addType` 2 and `deleteType` 2 go through a gateway. Without one, register refuses (`gateway_required`) and writes nothing. Remove marks the card Revoked and warns that the lock still accepts it until `ends_at` (the cloud record is not deleted: `deleteType` 1 needs the app at the lock).
- **To verify on a real lock:** that the lock opens for `add` with this byte order, how many cards a lock holds (the kiosk notes say 1000), and whether `date` skew or the 35 second remote timeout is enough through a gateway.

```
door_cards   id, property_id, door_lock_id, reservation_unit_id (nullable), card_number (bigint), ttlock_card_id (nullable),
             status (PasscodeStatus), label, starts_at, ends_at, error (nullable), revoked_at (nullable),
             idempotency_key (unique), timestamps
```

- A card is live (Pending or Active) once per lock and number (partial unique index). The same card can be registered again after it is Revoked.
- `RegisterDoorCard` (lock, UID, label, window, idempotency key, unit): same rules as `IssueDoorPasscode`: a live row is returned as it is, a Failed row is tried again, a TTLock failure is saved as Failed. A card live on the lock under another key is refused (`card_in_use`). A bad UID is `invalid_card`.
- `RemoveDoorCard`: deletes through the gateway and marks Revoked. A TTLock failure keeps the row.
- `StayByCard` (`app/Services/DoorLocks/`): `find($property, $uid)` returns the unit of the newest Active card with a stay, or null. This is how check-out by card finds the stay. A Revoked, Failed or Pending card finds nothing.
- Not built yet: choosing the lock and the window for a stay (the kiosk check-in Action will do it, as `SyncStayPasscodes` does for PINs), removing cards at check-out, and any staff screen.

## Lock health

`CheckDoorLockHealth` (the **Check health** button on a lock card, `POST locks/{doorLock}/health`) reads and saves, on the lock: battery and gateway flag (`/v3/lock/list`), the gateway it talks through and whether it is online (`/v3/gateway/list`, then `/v3/gateway/listLock` to find the lock's gateway), and the lock clock against ours (`/v3/lock/queryDate`, through the gateway, saved as `clock_drift_seconds`). A lock without a gateway only gets its battery. A gateway that cannot reach the lock keeps its online state and leaves the clock empty, with the message in the toast. TTLock counts a gateway online while its TCP link is up, so an unplugged one can show online for about eight minutes. The card shows battery (warns under 20%), gateway (warns when offline), clock (warns over 5 minutes off) and when it was last checked. Nothing checks health on its own: it is read when staff press the button.

## Actions and Jobs

- `SaveDoorLockConnection`: signs in with the username and password, saves the tokens, never the password.
- `TestDoorLockConnection`: reads the lock list and saves `last_tested_at` or `last_error`.
- `SyncDoorLocks`: upserts the lock list. Keeps room links. A lock that vanishes from TTLock is kept, with an old `synced_at`.
- `LinkDoorLock`: links or unlinks a room. Refuses a room of another property.
- `IssueDoorPasscode`: one verb for both kinds. A custom PIN on a lock without a gateway returns a refusal. Takes the PIN length for a random custom PIN.
- `ReplaceStayPasscode`: staff set a PIN for a checked-in stay (see PIN length and staff PIN).
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
- `DoorPins` (`resources/js/components/pms/reservations/door-pins.tsx`) shows current PINs as cards and the Revoked and Expired ones under "Earlier PINs". A queued job makes, moves and revokes PINs after check-in, check-out, undo check-in, a room move or a date change, so the page would show old data until a refresh. `UnitDoorPins` therefore compares what the stay needs with what exists and sends one flag, `UnitDoorPinsData.syncing`, true while: a PIN is being made (`making`), a live PIN sits on a lock the stay no longer uses (`leavingIds`), a PIN is Pending, or a live PIN's end is off (`updating`). The component passes that flag to `usePollWhile` (`resources/js/hooks/use-poll-while.ts`), which reloads only `doorPins` every 2 seconds while it is true, stops when it turns false, and gives up after 60 seconds (`gaveUp`, shown as "The door PINs did not finish updating"). Rows meanwhile show "Making the PIN…", "Updating the end time…" or "Removing from the lock…".
- To auto-refresh another page: put the "not settled yet" rule on the server next to the data, send it as a boolean, and call `usePollWhile(flag, { only: ['propName'] })` in the component that shows it.
- Each PIN shows its code, status badge, lock name and valid window. A failed PIN shows its error.
- A checked-in unit whose lock has no live PIN shows "No PIN yet for {lock}" with the reason when known: automatic PINs are off, or the stay has already ended so a PIN would be out of date.
- Revoked PINs stay in the list with the Revoked badge. On a lock without a gateway the row also shows the revoke note (see below) while the PIN's window is open.

## Permissions and routes

`settings.manage` behind the feature gate. Routes are under `/p/{code}/settings/door-locks`: `edit`, `locks`, `passcodes`, `update`, `sync`, `test`, `locks/{doorLock}/link`, `passcodes` (store), `passcodes/{doorPasscode}/revoke`, `passcodes/{doorPasscode}/retry`, `locks/{doorLock}/unlock`, `locks/{doorLock}/clock`. The staff PIN is `POST /p/{code}/reservations/{reservation}/units/{unit}/door-pins/{doorPasscode}/replace` (`reservations.units.door-pins.replace`), behind `front-desk.check-in`.

## Tests

`tests/Feature/DoorLocks/`: the password is not saved and tokens stay out of the page, a sync keeps links, a generated PIN is issued once for a stay (run twice, same row), a custom PIN is refused without a gateway, a card is registered once, refused without a gateway and found by UID (`DoorCardsTest`), remote unlock is refused without a gateway, check-out revokes, a revoke without a gateway warns, the feature off returns 404, another property's lock returns 404. `StayPasscodesTest` also covers the PIN length, the one retry on a clash, a typed PIN that clashes, the staff PIN replacing the current one, keeping it on a refusal, and the expiry job. `TtlockClientTest` fakes HTTP and covers the token refresh, the form fields, and an `errcode` response.

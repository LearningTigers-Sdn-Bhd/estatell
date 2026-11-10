# Estatell Keyport

Keyport is a small agent on the front desk PC. It writes guest key cards with a USB eLock encoder for jobs Estatell queues. The agent and the wire contract live in `../estatell-keyport/docs/ESTATELL_CONTRACT.md`. That file is the source of truth for the protocol. Change both repos together.

## What is built

The device API under `/api/keyport/v1`, the eLock lock provider, the staff screens and the check-in listener.

| Part                                                                                 | Where                                                                                                                                         |
| ------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------- |
| Tables `keyport_devices`, `keyport_pairing_codes`, `keyport_jobs`                    | `2026_10_29_010000_create_keyport_tables.php`; job links to lock and stay in `2026_11_06_120100_...`                                          |
| Routes: `pair`, `unpair`, `jobs/claim`, `jobs/{job}/result`                          | `routes/keyport.php`, loaded in `bootstrap/app.php`                                                                                           |
| Pairing code (single use, 10 minutes, only a hash saved)                             | `CreateKeyportPairingCode`, `PairKeyportDevice`                                                                                               |
| Device token (`kp_live_...`, only a SHA-256 hash saved)                              | `AuthenticateKeyportDevice`                                                                                                                   |
| Claim, heartbeat and lease                                                           | `RecordKeyportHeartbeat`, `SweepKeyportJobs`, `ClaimKeyportJob`                                                                               |
| Result, idempotent                                                                   | `ReportKeyportJobResult`                                                                                                                      |
| Revoke                                                                               | `RevokeKeyportDevice`                                                                                                                         |
| Lock provider `ttlock` or `elock` on each lock                                       | `LockProvider`, `door_locks.provider`, `door_locks.lock_no`                                                                                   |
| Add an eLock lock by number                                                          | `AddElockLock`                                                                                                                                |
| Make, rewrite, erase a card for a stay                                               | `CreateKeyportJob` (the only place that creates jobs), `CancelKeyportJob`                                                                     |
| Queue a card at check-in                                                             | `QueueStayKeyCards`, registered in `AppServiceProvider`                                                                                       |
| Settings > Door locks, eLock tab (computers, eLock locks, recent jobs, pairing code) | `DoorLockSettingsController::edit` (the `keyport` prop), `KeyportSettingsController` (actions), `components/pms/settings/keyport-section.tsx` |
| Key card panel on the stay                                                           | `ReservationKeyCardController`, `UnitKeyCards`, `components/pms/reservations/key-cards.tsx`                                                   |

Tests: `KeyportPairingTest`, `KeyportJobsTest`, `KeyportCardsTest` and `KeyportSettingsTest` in `tests/Feature/DoorLocks/`.

The eLock tab pages its lists on the server: locks (10 a page, `locks_page`, search in `lock_search`) and card jobs (10 a page, `jobs_page`). Revoked computers are hidden until you press Show revoked, and a revoked computer can be removed from the list. Its past jobs stay.

Staff can edit an eLock lock (name, number, room) and remove it from the row menu. Edit refuses a number or room another lock has. Remove is refused while a card job waits at the lock or a checked-in guest holds a card for it. Cards already written keep the old number after an edit, and are not erased by a remove.

Keyport is available only when the admin turned on the eLock provider for the property (see the feature gate in `01-ttlock-integration.md`).

## One provider for each lock

`door_locks.provider` is the only thing that decides how a lock works. Nothing is chosen per property or at run time, so a hotel can mix both.

| Provider | Lock has         | How a guest gets in                              |
| -------- | ---------------- | ------------------------------------------------ |
| `ttlock` | `ttlock_lock_id` | PIN, TTLock card or remote unlock from the cloud |
| `elock`  | `lock_no`        | A key card that Keyport writes at the front desk |

A database check keeps each row to one shape. `StayLocks::current()` and `for()` return TTLock locks only, so PIN and card code never touches an eLock lock. `currentElock()` returns the eLock locks of the room. Remote unlock, clock and health refuse an eLock lock. eLock locks are added by hand on Settings > Door locks (eLock tab), because eLock has no cloud list to sync.

A room has one lock (unique per property and room), so one room is either TTLock or eLock.

## Key card flow

1. Staff add a computer on Settings > Door locks (eLock tab), then type the pairing code into Keyport.
2. At check-in to an eLock room, `QueueStayKeyCards` makes one issue job, but only when the property has a computer that is not revoked. The job key is `checkin:{unit}:{lock}`, so a repeated event adds nothing.
3. On the stay, staff can also press Make key card, Update dates (rewrite) or Erase. Rewrite and erase name the serial of the last card this stay made. With no such card they are refused.
4. A job is offered for `keyport.job_minutes` (10). Nobody on the reader in time means `expired`.
5. Nothing is written until a person puts a card on the reader.
6. Check-out never erases a card by itself. Erase is a button. A card also ends by itself at the departure time written on it, but only on a lock whose clock is right: the lock compares the card with its own clock. A lock that was never set, or lost its time, keeps accepting a card after its departure (seen on a test lock: a card from Friday still opened on Monday). Set each lock's clock once from Keyport (Locks → Lock clock: make a clock card, swipe it at the lock within seconds) and again about every three months. After the clock was set, a card written for 10:50 opened at 10:51 and was refused at 10:52.
7. When a stay's dates change after a card was written, the stay panel shows "Dates changed" and Update dates becomes the main button. Nothing is queued by itself, because a card job only works while a person is at the reader.
8. After a room move the old room's card stays on the panel as "Old room" with Erase only. Erase is allowed at a lock outside the current room only when this stay made a card there. Issue and rewrite stay limited to the current room.

## Rules the API keeps

- The property always comes from the device token, never from the request.
- Only a hash of the device token and of the pairing code is stored. The token is shown once, at pairing.
- A property needs the Door locks feature on. Otherwise a device gets `403 door_locks_disabled`.
- `POST /pair` is limited to 10 requests a minute per IP.
- Job types are guest cards only (`issue`, `rewrite`, `erase`, `read`). There is no special-card type, so a master card cannot be queued.
- A claimed card write whose lease (120 s) ends with no result becomes `uncertain` and is never offered again. A read becomes `failed`.
- A pending job past `expires_at` becomes `expired` and is never offered.
- A result is idempotent. The same result again answers `204`. A different result for a finished job answers `409 job_already_finished`.
- Updated agents send `long_poll: false`. Estatell checks once and responds immediately; after `204` the agent waits two seconds locally before checking again. Idle agents no longer occupy a PHP worker. A ready job is returned immediately; a job queued between checks waits up to two seconds, plus request time.
- Agents that omit `long_poll` or send `true` retain the legacy wait of up to `keyport.poll_seconds` (25). Each legacy agent can hold one PHP worker. Deploy the server and rebuild/restart Keyport to remove that load; deploying only the server does not fix older agents. New agents also work against older servers, which ignore the field and still long poll. Do not set `KEYPORT_POLL_SECONDS=0` while older agents remain: they immediately re-poll empty responses.

## Not built yet

1. Queueing rewrite or erase by itself on room move, date change and check-out. The panel flags what is out of date and staff press the button.
2. A start-time issuer for the check-in flag (128). See Lock numbers.
3. Special cards. A cloud job can never make one.

## Lock numbers

Guest-card jobs use RF57 lock numbers in `building.floor.room[.subroom]` format. Building and floor are 1–255, room is 0–999999, optional subroom is 0–127. Example: `1.1.101`. Do not use a display room number such as `0101` as the encoded lock number.

The current issuer supplies arrival=now. Requests with the check-in-time flag (128) are refused until a start-time issuer exists. Job timestamps are property-local; the agent PC must use the property timezone in v1.

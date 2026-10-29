# Estatell Keyport

Keyport is a small agent on the front desk PC. It writes guest key cards with a USB eLock encoder for jobs Estatell queues. The agent and the wire contract live in `../estatell-keyport/docs/ESTATELL_CONTRACT.md`. That file is the source of truth for the protocol. Change both repos together.

## What is built

The device API under `/api/keyport/v1`. Staff screens and job creation are not built yet.

| Part                                                              | Where                                                             |
| ----------------------------------------------------------------- | ----------------------------------------------------------------- |
| Tables `keyport_devices`, `keyport_pairing_codes`, `keyport_jobs` | `database/migrations/2026_10_29_010000_create_keyport_tables.php` |
| Routes: `pair`, `unpair`, `jobs/claim`, `jobs/{job}/result`       | `routes/keyport.php`, loaded in `bootstrap/app.php`               |
| Pairing code (single use, 10 minutes, only a hash saved)          | `CreateKeyportPairingCode`, `PairKeyportDevice`                   |
| Device token (`kp_live_...`, only a SHA-256 hash saved)           | `AuthenticateKeyportDevice`                                       |
| Claim, heartbeat and lease                                        | `RecordKeyportHeartbeat`, `SweepKeyportJobs`, `ClaimKeyportJob`   |
| Result, idempotent                                                | `ReportKeyportJobResult`                                          |
| Revoke                                                            | `RevokeKeyportDevice`                                             |
| Error codes and statuses                                          | `KeyportApiError`, `KeyportJobStatus`, `KeyportJobType`           |
| Settings (poll, lease, pairing time)                              | `config/keyport.php`                                              |

Tests: `tests/Feature/DoorLocks/KeyportPairingTest.php` and `KeyportJobsTest.php`.

## Rules the API keeps

- The property always comes from the device token, never from the request.
- Only a hash of the device token and of the pairing code is stored. The token is shown once, at pairing.
- A property needs the Door locks feature on. Otherwise a device gets `403 door_locks_disabled`.
- `POST /pair` is limited to 10 requests a minute per IP.
- Job types are guest cards only (`issue`, `rewrite`, `erase`, `read`). There is no special-card type, so a master card cannot be queued.
- A claimed card write whose lease (120 s) ends with no result becomes `uncertain` and is never offered again. A read becomes `failed`.
- A pending job past `expires_at` becomes `expired` and is never offered.
- A result is idempotent. The same result again answers `204`. A different result for a finished job answers `409 job_already_finished`.
- A claim waits up to `keyport.poll_seconds` (25) for work. This holds one PHP worker per connected PC. Set `KEYPORT_POLL_SECONDS` lower if workers run short.

## Not built yet

1. Staff UI: Settings > Door locks > Keyport (add computer and show a pairing code, device list with online state, revoke), a "Make key card" action on a stay, and a job status panel.
2. An action that creates jobs (`keyport_jobs` rows), with `issued_by` and an idempotency key.
3. The `ELock` lock provider on `door_locks` (`lock_no` per room).
4. The check-in listener. It would only queue a job. A person still puts the card on the reader.

## Lock numbers

Guest-card jobs use RF57 lock numbers in `building.floor.room[.subroom]` format. Building and floor are 1–255, room is 0–999999, optional subroom is 0–127. Example: `1.1.101`. Do not use a display room number such as `0101` as the encoded lock number.

The current issuer supplies arrival=now. Requests with the check-in-time flag (128) are refused until a start-time issuer exists. Job timestamps are property-local; the agent PC must use the property timezone in v1.

# New-device check

## Purpose

A stolen password alone should not open an account. When a user signs in with their password on a browser they have not used before, estatell emails a 6-digit code. The browser is then trusted for that user for 90 days after its last sign-in.

## Who gets the check

| User                                       | Check | Why                                                      |
| ------------------------------------------ | ----- | -------------------------------------------------------- |
| Owners, invited staff, partners, companies | Yes   | Their email is real: they received an invite or a login  |
| Staff created with a temp password         | No    | The owner may have made the email up (training accounts) |
| Users with two-factor turned on            | No    | Fortify's two-factor challenge runs instead (stronger)   |
| Platform admins                            | No    | Admins use two-factor (`SUPPORT_REQUIRE_2FA`)            |

`TrustedDevices::requiresCheck()` holds this rule. `users.email_device_check` is false for temp-password staff (`CreateStaffWithTemporaryPassword`, also used by bulk add and import). The migration set it false for existing staff who still have a temp password or were created with one (`staff.created-with-temp-password` in the activity log).

Sign-ins that already prove the email (accepting an invite, owner setup, partner invite) log in directly and trust nothing.

## Flow

```
/login → email + password
  ├─ wrong password            → normal error, no code sent
  ├─ two-factor on             → /two-factor-challenge (Fortify)
  ├─ no check needed           → signed in
  ├─ browser trusted for user  → signed in, trust moves forward 90 days
  └─ new browser               → code emailed → /login/verify-device
                                   ├─ right code → signed in, browser trusted, home
                                   ├─ wrong code → "This code is wrong or has expired…" (counted)
                                   └─ Resend code (once a minute)
```

The check runs inside Fortify's login pipeline. `RedirectIfTwoFactorOrNewDevice` extends Fortify's two-factor step and is registered with `Fortify::redirectUserForTwoFactorAuthenticationUsing()`, so the password is checked once and the rest of the pipeline is Fortify's own. While the code is pending, the session holds `login.device.id` and `login.device.remember`. Nobody is signed in until the code is right.

## Security

| Concern          | Rule                                                                                                     |
| ---------------- | -------------------------------------------------------------------------------------------------------- |
| Code             | 6 digits from `random_int`, stored as a password hash (`Hash::make`), never in plain text                |
| Expiry           | 15 minutes (`NewDeviceCodes::VALID_MINUTES`)                                                             |
| Wrong codes      | 5 per code (`NewDeviceCodes::MAX_ATTEMPTS`), then a new code is needed. Route limit 10 per minute        |
| Resend           | Once a minute. A new code replaces the old one                                                           |
| Email            | Sent at once, not queued, so a stopped queue never blocks sign-in. The mail log never keeps the body     |
| Device cookie    | `estatell_device`: a random 40-character token, encrypted, HTTP-only, SameSite Lax, 90 days              |
| Stored token     | Only its SHA-256 hash, per user, in `user_trusted_devices`                                               |
| Shared PCs       | One token per browser. Each user who verifies on it gets their own row, so a front desk PC serves a team |
| Deactivated user | A user deactivated while the code is pending is not signed in                                            |

## Data

```
users                + email_device_check  boolean, default true

user_trusted_devices   id, user_id → users (cascade), token_hash char(64), device_name (e.g. "Chrome on macOS"),
                       last_used_at, expires_at (index), timestamps
                       UNIQUE (user_id, token_hash)

email_otps             id, user_id → users (cascade, unique), code_hash, attempts, expires_at, created_at
```

No IP address is stored for a trusted device. The sign-in log already records each sign-in (see [Sign-in log](07-sign-in-log.md)).

## Email

Subject "123456 is your estatell sign-in code". It shows the code, the device ("Chrome on macOS"), the IP address and the Cloudflare city and country when known, says the code expires in 15 minutes, and tells the user to change their password if it was not them.

## Routes

| Method | URL                           | Name               | Purpose                               |
| ------ | ----------------------------- | ------------------ | ------------------------------------- |
| GET    | `/login/verify-device`        | `login.otp.show`   | Code page, with the masked email      |
| POST   | `/login/verify-device`        | `login.otp.store`  | Check the code (`throttle:login-otp`) |
| POST   | `/login/verify-device/resend` | `login.otp.resend` | Send a new code                       |

All are `guest`. Without a pending sign-in in the session they go back to `/login`.

## Pieces

| Piece        | Location                                                                                     |
| ------------ | -------------------------------------------------------------------------------------------- |
| Login step   | `app/Actions/Fortify/RedirectIfTwoFactorOrNewDevice.php`                                     |
| Actions      | `app/Actions/Account/SendNewDeviceCode.php`, `ConfirmNewDeviceSignIn.php`, `TrustDevice.php` |
| Services     | `app/Services/Account/TrustedDevices.php`, `NewDeviceCodes.php`, `VisitorLocation.php`       |
| Controller   | `app/Http/Controllers/Auth/DeviceVerificationController.php`                                 |
| Page         | `resources/js/pages/auth/verify-device.tsx`                                                  |
| Notification | `app/Notifications/NewDeviceCodeNotification.php`                                            |

## Not yet

- A list of trusted devices on the account security page, with "Forget this device".
- An owner switch to turn the check on for a temp-password staff member who later sets a real email.
- Pruning expired `user_trusted_devices` rows.

## Tests

`tests/Feature/Account/DeviceVerificationTest.php`: new device gets a code, right code signs in and trusts the browser, trusted browser skips the code and moves the trust forward, shared browser, expired or other user's trust, wrong and used-up codes, expired code, deactivated user, resend cooldown, no pending sign-in, admins, two-factor users, temp-password staff, wrong password.

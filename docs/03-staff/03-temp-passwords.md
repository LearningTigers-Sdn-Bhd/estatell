# Temp passwords and bulk staff

## Purpose

Owners add staff without email: one at a time, in bulk, or from a CSV or XLSX file. The server gives each new user a temp password. The owner hands it out by copying a message or exporting CSV, XLSX or PDF. Staff sign in on the normal login page and must set their own password first.

Use cases: properties where staff have no working email, and training institutions that create a class of accounts and print the sign-in slips.

Emailed invitations stay as they are. See [Staff and invitations](01-staff-and-invitations.md).

## Data model

```
users   + temporary_password             text, nullable, encrypted cast
        + temporary_password_expires_at  timestamp, nullable
        + must_change_password           boolean, default false
        CHECK (temporary_password IS NULL) = (temporary_password_expires_at IS NULL)
```

- `password` holds the hash of the temp password, so the normal login works.
- `temporary_password` is a readable copy for export and copy only. It is encrypted with `APP_KEY` (not hashed), so it is wiped as soon as it is not needed: on first password change, on reset and when expired.
- `email_verified_at` is set at creation. The owner vouches for the person, so made-up emails (training accounts) work.
- A temp password expires 14 days after it is created (`CreateStaffWithTemporaryPassword::VALID_DAYS`).

## Temp password format

Two words and four digits, for example `Kopi-7342-Meja`. Words come from a short built-in list of plain Malay and English words without look-alike characters (`TemporaryPasswordGenerator`). This is enough because login is throttled (5 per minute), the password expires in 14 days, and it is used once.

## Sign-in flow

```
/login → email + temp password
  ├─ expired     → "This temporary password has expired. Ask your property owner for a new one."
  ├─ deactivated → existing message
  └─ ok → /account/set-password
           New password + confirm (Password::defaults())
           → clear the temp columns, must_change_password = false → normal home redirect
```

- The expired check lives in `Fortify::authenticateUsing`, next to the deactivated check.
- `EnsurePasswordChanged` (appended to the `web` group) sends a signed-in user with `must_change_password` to `/account/set-password` on every request. Only the set-password routes and `logout` are exempt.
- Setting the password ends other sessions through `auth.session`. See [Routing](../routing.md#sessions).

## Pages

### Staff list

```
Staff                                      [Add staff ▾]
                                             ├─ Invite by email
                                             ├─ Add with temp password
                                             └─ Bulk add
┌──┬──────────────┬───────────────┬────────────┬───────────────────┐
│☐ │ Name         │ Email         │ Role       │ Status            │
│☐ │ Ali Hassan   │ ali@hotel.com │ Front desk │ Temp password · 9d│
│☐ │ Siti Aminah  │ siti@...      │ Housekeep. │ Active            │
└──┴──────────────┴───────────────┴────────────┴───────────────────┘
Selected (1): [Copy message] [Export ▾ CSV · XLSX · PDF] [Reset temp password]
```

- Rows can be selected only by users with `staff.manage`.
- The Status column shows **Temp password · Nd** (days left) or **Temp password expired**.
- Copy and export use only selected rows with an unexpired temp password. Other rows are skipped and the toast names the count, for example "1 skipped: no temp password".
- The page never receives temp passwords as props. Copy fetches the text from the message route; exports are downloads.
- Each active row also has **Copy message** (when its temp password is unexpired) and **Reset password** (not for Owners or yourself). After a reset, the copy message opens for that person.

### Add with temp password (Dialog, 3 fields)

Name, email, role. After saving, a Dialog shows the copy message with **Copy**.

### Bulk add (full page)

A full page, because it has repeating rows and a file upload ([Form containers](../design-system/05-form-containers.md)).

```
Settings › Staff › Bulk add
┌─────────────────────────────────────────────────────────┐
│ Name            Email               Role            ✕   │
│ Ali Hassan      ali@hotel.com       Front desk   ▾  ✕   │
│ Siti Aminah     siti@hotel.com      Housekeeping ▾  ✕   │
│ ⚠ Email already in use                                  │
│ [+ Row]   [Upload CSV / XLSX]   [Download template ▾]   │
│                               [Cancel] [Create 2 staff] │
└─────────────────────────────────────────────────────────┘
```

- Upload parses the file and adds its rows after the filled grid rows. Nothing is saved until **Create**. Files are CSV or XLSX, at most 1 MB.
- File columns: `name`, `email`, `role` (header names ignore case and spaces; blank lines are skipped). The role matches a role the owner may give, by name, ignoring case. An unknown or empty role leaves the cell empty with an error on that row. **Template** downloads an empty CSV or XLSX with the header row.
- Up to 200 rows (`StaffImportReader::MAX_ROWS`). Errors show on their row, including an email that is in the list twice. Create is all or nothing (one transaction).
- After **Create**, the page shows the created staff with **Copy all**, **Export CSV / XLSX / PDF** (with the export commit) and **Done** (back to the staff list).

## Exports and copy

| Output       | Content                                                         | How                                               |
| ------------ | --------------------------------------------------------------- | ------------------------------------------------- |
| CSV / XLSX   | Property, name, email, role, temp password, expires, login URL  | `spatie/simple-excel`, streamed download          |
| PDF          | One slip per person, 8 per A4 page, cut lines                   | `barryvdh/laravel-dompdf`                         |
| Copy message | The text below, one block per person, separated by a blank line | Message route returns text, the browser copies it |

```
1. Open: estatell.com/login
2. Email: ali@hotel.com
3. Temp password: Kopi-7342-Meja
4. Set your own password when asked (expires 18 Oct)
```

The login URL is `route('login')`, so it follows `APP_URL`.

## Actions and services

| Class                                        | Does                                                                                                                        |
| -------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| `Staff\CreateStaffWithTemporaryPassword`     | Creates one verified user with a role and a temp password. Logs `staff.created-with-temp-password`                          |
| `Staff\BulkCreateStaff`                      | Validates all rows, then calls the single create for each in one transaction                                                |
| `Staff\ResetTemporaryPassword`               | New temp password and expiry, sets `must_change_password`, which ends the user's sessions. Logs `staff.temp-password-reset` |
| `Staff\ExportTemporaryPasswords`             | Builds the CSV, XLSX, PDF or message text for selected users. Logs `staff.temp-passwords-exported` with format and user ids |
| `Account\SetOwnPassword`                     | Saves the new password, clears the temp columns and `must_change_password`                                                  |
| `Staff\TemporaryPasswordGenerator` (service) | Makes `Word-0000-Word` passwords                                                                                            |
| `Staff\TemporaryPasswordMessage` (service)   | Builds the copy message text from the slips                                                                                 |
| `Staff\StaffImportReader` (service)          | Reads CSV or XLSX into rows (`name`, `email`, `role`), at most 200                                                          |
| `Staff\StaffImportTemplate` (service)        | Writes the empty import file for a `SpreadsheetFormat` (`csv`, `xlsx`)                                                      |

- `TemporaryPasswordExportFormat` (enum) names the format in the export log: `message` now, `csv`, `xlsx` and `pdf` with the export commit.
- Activity logs never contain a password.
- Reset returns `false` for yourself, an Owner and a deactivated user.
- Emails follow the invitation rules: stored lower case, and an email with an account or an open invitation cannot be used.

## Routes

All under `/p/{code}/settings/staff` with `staff.manage`.

| Method | URL                                  | Name                                    |
| ------ | ------------------------------------ | --------------------------------------- |
| POST   | `.../staff`                          | `settings.staff.store`                  |
| GET    | `.../staff/bulk`                     | `settings.staff.bulk.create`            |
| POST   | `.../staff/bulk`                     | `settings.staff.bulk.store`             |
| POST   | `.../staff/import`                   | `settings.staff.import` (rows JSON)     |
| GET    | `.../staff/import-template.{format}` | `settings.staff.import-template`        |
| POST   | `.../staff/temp-passwords/reset`     | `settings.staff.temp-passwords.reset`   |
| POST   | `.../staff/temp-passwords/export`    | `settings.staff.temp-passwords.export`  |
| POST   | `.../staff/temp-passwords/message`   | `settings.staff.temp-passwords.message` |

Account routes: `GET /account/set-password` and `PUT /account/set-password` (`account.set-password.edit` and `.update`), `auth` only.

## Not included

- Admins giving owners a temp password. The admin "Initial password" option stays ([Account creation](../17-onboarding/01-account-creation.md)). It can move to this pattern later.
- Passwords in the upload file. The server always generates them.
- Passkeys and passwordless sign-in.

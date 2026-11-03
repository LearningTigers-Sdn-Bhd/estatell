# MyKad reader

## Purpose

Fill guest details straight from the chip on a guest's MyKad. Staff insert the card into a USB reader at the front desk. The guest form fills in under 2 seconds, and staff check it before saving.

The reader program is **MyKad Reader**, version 2.0.0 and later. It lives in its own repo, `LearningTigers-Sdn-Bhd/estatell-mykad-reader`. That repo holds the card, HTTP, packaging and tray docs. This page covers only the Estatell side.

## How it works

```
Browser (estatell)  ──HTTP──▶  MyKad Reader on 127.0.0.1:9292  ──PC/SC──▶  ACR39U reader  ──▶  MyKad chip
```

- The browser cannot talk to a USB card reader. MyKad Reader runs on the front-desk computer and serves the card as JSON on `127.0.0.1:9292`.
- It listens only on `127.0.0.1`, answers only allowed origins (Estatell and its demo), and has no pairing and no token.
- Estatell polls `GET /status` every 2.5 seconds. When a card appears, it calls `GET /read` and fills the active form.
- The server still validates and stores everything. MyKad Reader only fills the form.

API fields, error codes and security: see the reader repo, [HTTP API](https://github.com/LearningTigers-Sdn-Bhd/estatell-mykad-reader/blob/main/docs/02-bridge/01-http-api.md).

## Hardware

| Item    | Supported                                                                 |
| ------- | ------------------------------------------------------------------------- |
| Readers | ACS ACR39U and other USB CCID contact readers. No driver is needed.       |
| OS      | Windows 10 and 11, macOS 15 or later. Other systems show "not supported". |

## In the app

- `useMykadReader` (`resources/js/hooks/use-mykad-reader.ts`) polls `/status`. When a card is inserted, it reads the card and fills the active form.
- `MyKadScanner` (`resources/js/components/pms/mykad-scanner.tsx`) is a status chip. It has no settings page and no download button. An admin installs MyKad Reader and it works.
- The chip is on every screen that has the MyKad box: the check-in dialog, the guests section on New reservation and Walk-in, the reservation Guests tab, the registration card, and the guest document sheet.
- When staff add a document from a scan, the portrait becomes the `id_front` file. See [Identity documents](02-identity-documents.md).

### Chip states

| State                     | Chip                                            | On click                                       |
| ------------------------- | ----------------------------------------------- | ---------------------------------------------- |
| Reader ready              | ● Ready for MyKad                               | —                                              |
| Reading                   | Reading the card…                               | —                                              |
| No reader                 | ○ Plug in card reader                           | Opens `mykad-reader-problems` in the user docs |
| `/status` does not answer | ○ MyKad Reader isn't running                    | Opens `set-up-mykad-reader` in the user docs   |
| Update required (1.x)     | ⚠ Update required                               | Opens the download for this computer           |
| Other system              | ○ MyKad Reader isn't supported on this computer | —                                              |

"Update required" means the `version` in `/status` is lower than `REQUIRED_BRIDGE_VERSION` (`2.0.0`). A 1.x reader has no `code` on `/read`, so the hook falls back to its `error` text.

### Read errors

The hook maps the `code` from `/read` to Estatell's own words. `error` is for logs only.

| `code`        | Message                                                                 |
| ------------- | ----------------------------------------------------------------------- |
| `no_reader`   | Plug the card reader into this computer.                                |
| `no_card`     | Insert the MyKad, chip first and face up.                               |
| `busy`        | Still reading the last card. Try again in a moment.                     |
| `read_failed` | The card couldn't be read. Take it out and insert it again.             |
| `forbidden`   | MyKad Reader refused this page. Fix this ↗ (links to the problems page) |

### Downloads

The check-in chip and the user docs link to two public Estatell addresses. They redirect to the universal installer of the version in `MYKAD_READER_VERSION`, so staff never choose a chip:

| OS      | Link                              |
| ------- | --------------------------------- |
| Windows | `/downloads/mykad-reader/windows` |
| macOS   | `/downloads/mykad-reader/mac`     |

For a new release, tag it in the reader repo, change `MYKAD_READER_VERSION` and restart Estatell. It can be newer than `REQUIRED_BRIDGE_VERSION`. A version with no GitHub release gives staff a 404 from GitHub.

### User docs links

| Page                                                                                | Used for           |
| ----------------------------------------------------------------------------------- | ------------------ |
| `https://docs.estatell.com/en/docs/guests/identity-documents/set-up-mykad-reader`   | Install or restart |
| `https://docs.estatell.com/en/docs/guests/identity-documents/mykad-reader-problems` | Problems           |

### Words on screen

Screens are for hotel owners and staff. Say "MyKad Reader", not "bridge". Never show origin, CORS, port, localhost, code signing or "403".

## Release rules

- Ship this change in the same Estatell release as MyKad Reader 2.0.0, and not before 2.0.0 is released. Until then, staff with 1.x would be told to update to a version that does not exist.
- The repo `estatell-mykad-reader` must be public before release. Hotels download from its GitHub Releases without a login.
- A release build of MyKad Reader refuses `localhost` and `*.test` origins unless `ESTATELL_BRIDGE_ORIGINS` lists them. For local development, use a debug build or set that variable.
- To require a newer reader later, bump `REQUIRED_BRIDGE_VERSION` in the same Estatell release.

## Open

- Test on a real reader: ready, no reader, reader unplugged mid-use, 1.x reader, `forbidden`.

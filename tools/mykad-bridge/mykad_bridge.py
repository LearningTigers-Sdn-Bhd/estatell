#!/usr/bin/env python3
"""
Estatell MyKad Bridge Daemon
Runs locally on front-desk workstations to bridge the ACR39U reader to the Estatell web app.
Port: 127.0.0.1:9292
Multi-threaded, supports Chrome Private Network Access (PNA) and fast non-blocking status.

Only Estatell origins may call it (see ALLOWED_ORIGINS). Extra origins can be added with the
ESTATELL_BRIDGE_ORIGINS environment variable (comma separated).
"""

import os
import re
import sys
import json
import base64
import errno
import socket
import struct
import logging
from logging.handlers import RotatingFileHandler
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse

__version__ = "1.1.0"

HOST = "127.0.0.1"
PORT = 9292

# Exact origins, plus any localhost / *.test origin for development.
ALLOWED_ORIGINS = {
    "https://app.estatell.com",
    "https://demo.estatell.com",
}
ALLOWED_ORIGINS.update(
    o.strip().rstrip("/") for o in os.environ.get("ESTATELL_BRIDGE_ORIGINS", "").split(",") if o.strip()
)
DEV_ORIGIN = re.compile(r"^https?://(localhost|127\.0\.0\.1|[a-z0-9-]+(\.[a-z0-9-]+)*\.test)(:\d+)?$")
ALLOWED_HOSTS = {f"127.0.0.1:{PORT}", f"localhost:{PORT}"}


def log_path():
    if sys.platform == "win32":
        base = os.path.join(os.environ.get("LOCALAPPDATA") or os.path.expanduser("~"), "Estatell")
    elif sys.platform == "darwin":
        base = os.path.expanduser("~/Library/Logs")
    else:
        base = os.path.expanduser("~/.local/state")
    os.makedirs(base, exist_ok=True)
    return os.path.join(base, "estatell-mykad-bridge.log")


def setup_logging():
    """Log to a capped file. A windowless build has no console, so stdout may be None."""
    handlers = [RotatingFileHandler(log_path(), maxBytes=1_000_000, backupCount=2, encoding="utf-8")]
    if sys.stderr is not None:
        handlers.append(logging.StreamHandler(sys.stderr))
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s", handlers=handlers)


log = logging.getLogger("mykad-bridge")

try:
    from smartcard.System import readers
    from smartcard.scard import SCARD_SHARE_SHARED, SCARD_LEAVE_CARD
    from smartcard.Exceptions import NoCardException, CardConnectionException
except ImportError:
    setup_logging()
    log.error("pyscard is not installed. Run: pip3 install pyscard")
    sys.exit(1)


def is_allowed_origin(origin):
    origin = origin.rstrip("/")
    return origin in ALLOWED_ORIGINS or bool(DEV_ORIGIN.match(origin))

JPN_AID = [0x00, 0xA4, 0x04, 0x00, 0x0A, 0xA0, 0x00, 0x00, 0x00, 0x74, 0x4A, 0x50, 0x4E, 0x00, 0x10]

# Mapping Malaysian state strings to LHDN state codes (matching EInvoice::MalaysiaStates)
STATE_CODES = {
    "JOHOR": "01",
    "KEDAH": "02",
    "KELANTAN": "03",
    "MELAKA": "04",
    "MALACCA": "04",
    "NEGERI SEMBILAN": "05",
    "N. SEMBILAN": "05",
    "PAHANG": "06",
    "PULAU PINANG": "07",
    "PENANG": "07",
    "PERAK": "08",
    "PERLIS": "09",
    "SELANGOR": "10",
    "TERENGGANU": "11",
    "SABAH": "12",
    "SARAWAK": "13",
    "KUALA LUMPUR": "14",
    "W.P. KUALA LUMPUR": "14",
    "WILAYAH PERSEKUTUAN KUALA LUMPUR": "14",
    "LABUAN": "15",
    "W.P. LABUAN": "15",
    "WILAYAH PERSEKUTUAN LABUAN": "15",
    "PUTRAJAYA": "16",
    "W.P. PUTRAJAYA": "16",
    "WILAYAH PERSEKUTUAN PUTRAJAYA": "16",
}

def resolve_state_code(state_str, postcode_str=""):
    clean = state_str.upper().strip()
    for name, code in STATE_CODES.items():
        if name in clean:
            return code
    if postcode_str and len(postcode_str) == 5 and postcode_str.isdigit():
        prefix = int(postcode_str[:2])
        if 88 <= prefix <= 91:
            return "12"  # Sabah
        if 93 <= prefix <= 98:
            return "13"  # Sarawak
        if 50 <= prefix <= 60:
            return "14"  # KL
        if 87 <= prefix <= 87:
            return "15"  # Labuan
        if 62 <= prefix <= 62:
            return "16"  # Putrajaya
        if 40 <= prefix <= 48 or 63 <= prefix <= 68:
            return "10"  # Selangor
        if 80 <= prefix <= 86:
            return "01"  # Johor
        if 30 <= prefix <= 39:
            return "08"  # Perak
        if 10 <= prefix <= 14:
            return "07"  # Penang
    return ""

def bcd_to_int(bcd_bytes):
    val = 0
    for b in bcd_bytes:
        val = (val * 100) + ((b >> 4) & 0x0F) * 10 + (b & 0x0F)
    return val

def parse_iso_date(bcd_bytes):
    try:
        year = ((bcd_bytes[0] >> 4) & 0x0F) * 1000 + (bcd_bytes[0] & 0x0F) * 100 + \
               ((bcd_bytes[1] >> 4) & 0x0F) * 10 + (bcd_bytes[1] & 0x0F)
        month = ((bcd_bytes[2] >> 4) & 0x0F) * 10 + (bcd_bytes[2] & 0x0F)
        day = ((bcd_bytes[3] >> 4) & 0x0F) * 10 + (bcd_bytes[3] & 0x0F)
        return f"{year:04d}-{month:02d}-{day:02d}"
    except Exception:
        return ""

def format_ic(raw_ic):
    clean = raw_ic.strip().replace("-", "")
    if len(clean) == 12:
        return f"{clean[:6]}-{clean[6:8]}-{clean[8:]}"
    return clean

class MyKadReaderSession:
    def __init__(self, connection):
        self.conn = connection

    def select_application(self):
        data, sw1, sw2 = self.conn.transmit(JPN_AID)
        if sw1 not in (0x90, 0x61):
            raise RuntimeError(f"Select JPN Application failed (SW1={hex(sw1)}, SW2={hex(sw2)})")

    def set_length(self, length):
        param = [0x08, 0x00, 0x00] + list(struct.pack('<H', length))
        cmd = [0xC8, 0x32, 0x00, 0x00, len(param)] + param
        data, sw1, sw2 = self.conn.transmit(cmd)
        if sw1 not in (0x90, 0x91):
            raise RuntimeError(f"SetLength failed (SW1={hex(sw1)}, SW2={hex(sw2)})")

    def select_info(self, filen1, filen2, offset, length):
        param = list(struct.pack('<HHHH', filen2, filen1, offset, length))
        cmd = [0xCC, 0x00, 0x00, 0x00, len(param)] + param
        data, sw1, sw2 = self.conn.transmit(cmd)
        if sw1 not in (0x90, 0x91, 0x94):
            raise RuntimeError(f"SelectInfo failed (SW1={hex(sw1)}, SW2={hex(sw2)})")

    def read_binary(self, length):
        buf = bytearray()
        remaining = length
        while remaining > 0:
            chunk = min(remaining, 255)
            cmd = [0xCC, 0x06, 0x00, 0x00, chunk]
            data, sw1, sw2 = self.conn.transmit(cmd)
            if sw1 not in (0x90, 0x91, 0x94):
                raise RuntimeError(f"ReadBinary failed (SW1={hex(sw1)}, SW2={hex(sw2)})")
            buf.extend(data)
            remaining -= chunk
        return buf

    def read_mykad_data(self):
        self.select_application()

        # 1. Basic Info (JPN1_1: 459 bytes)
        self.set_length(459)
        self.select_info(1, 1, 0, 459)
        jpn1_1 = self.read_binary(459)

        # 2. Address (JPN1_4: 171 bytes)
        self.set_length(171)
        self.select_info(1, 4, 0, 171)
        jpn1_4 = self.read_binary(171)

        # 3. Photo (JPN1_2: 4011 bytes)
        photo_b64 = None
        try:
            self.set_length(4011)
            self.select_info(1, 2, 0, 4011)
            jpn1_2 = self.read_binary(4011)
            photo_bytes = bytes(jpn1_2[3:4003])
            if len(photo_bytes) > 100 and photo_bytes[0] == 0xFF and photo_bytes[1] == 0xD8:
                photo_b64 = "data:image/jpeg;base64," + base64.b64encode(photo_bytes).decode('ascii')
        except Exception as e:
            log.warning("Photo warning: %s", e)

        name = jpn1_1[3:153].decode("ascii", errors="replace").strip()
        raw_ic = jpn1_1[0x111:0x111+13].decode("ascii", errors="replace").strip()
        gender_code = jpn1_1[0x11E:0x11F].decode("ascii", errors="replace").strip()
        gender = "male" if gender_code.upper() == "L" else "female"
        old_ic = jpn1_1[0x11F:0x11F+8].decode("ascii", errors="replace").strip()
        dob = parse_iso_date(jpn1_1[0x127:0x127+4])
        pob = jpn1_1[0x12B:0x12B+25].decode("ascii", errors="replace").strip()
        citizenship = jpn1_1[0x148:0x148+18].decode("ascii", errors="replace").strip()
        race = jpn1_1[0x15A:0x15A+25].decode("ascii", errors="replace").strip()
        religion = jpn1_1[0x173:0x173+11].decode("ascii", errors="replace").strip()

        addr1 = jpn1_4[3:33].decode("ascii", errors="replace").strip()
        addr2 = jpn1_4[0x21:0x21+30].decode("ascii", errors="replace").strip()
        addr3 = jpn1_4[0x3F:0x3F+30].decode("ascii", errors="replace").strip()
        try:
            postcode_num = bcd_to_int(jpn1_4[0x5D:0x5D+3]) // 10
            postcode = f"{postcode_num:05d}"
        except Exception:
            postcode = ""
        city = jpn1_4[0x60:0x60+25].decode("ascii", errors="replace").strip()
        raw_state = jpn1_4[0x79:0x79+30].decode("ascii", errors="replace").strip()
        state_code = resolve_state_code(raw_state, postcode)

        addr_parts = [line for line in [addr1, addr2, addr3] if line]
        home_address = ", ".join(addr_parts)

        return {
            "name": name,
            "government_id": format_ic(raw_ic),
            "raw_ic": raw_ic,
            "old_ic": old_ic,
            "document_type": "malaysian_nric",
            "country": "Malaysia",
            "gender": gender,
            "date_of_birth": dob,
            "place_of_birth": pob,
            "citizenship": citizenship,
            "race": race,
            "religion": religion,
            "home_address": home_address,
            "address_line1": addr1,
            "address_line2": addr2,
            "address_line3": addr3,
            "city": city.title(),
            "postal_code": postcode,
            "state_name": raw_state.title(),
            "state_code": state_code,
            "address_country": "Malaysia",
            "photo_base64": photo_b64
        }

class BridgeHTTPHandler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def _send_cors_headers(self):
        origin = self.headers.get("Origin")
        if origin and is_allowed_origin(origin):
            self.send_header("Access-Control-Allow-Origin", origin)
            self.send_header("Vary", "Origin")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, Accept, Authorization, X-Requested-With, Access-Control-Request-Private-Network")
        self.send_header("Access-Control-Allow-Private-Network", "true")
        self.send_header("Connection", "close")
        self.send_header("Cache-Control", "no-store, no-cache, must-revalidate")

    def _reject_foreign_request(self):
        """Refuse other websites (Origin) and DNS-rebinding hosts (Host) before touching the card."""
        origin = self.headers.get("Origin")
        host = (self.headers.get("Host") or "").lower()
        if (origin and not is_allowed_origin(origin)) or host not in ALLOWED_HOSTS:
            log.warning("Rejected request from origin=%s host=%s", origin, host)
            body = b'{"error": "Origin not allowed"}'
            self.send_response(403)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Connection", "close")
            self.end_headers()
            self.wfile.write(body)
            self.close_connection = True
            return True
        return False

    def do_OPTIONS(self):
        if self._reject_foreign_request():
            return
        self.send_response(204)
        self._send_cors_headers()
        self.send_header("Content-Length", "0")
        self.end_headers()
        self.close_connection = True

    def do_GET(self):
        if self._reject_foreign_request():
            return
        parsed = urlparse(self.path)
        if parsed.path in ("/status", "/health", "/"):
            self.handle_status()
        elif parsed.path == "/read":
            self.handle_read()
        else:
            self.send_error(404, "Not Found")
        self.close_connection = True

    def do_POST(self):
        if self._reject_foreign_request():
            return
        parsed = urlparse(self.path)
        if parsed.path == "/read":
            self.handle_read()
        else:
            self.send_error(404, "Not Found")
        self.close_connection = True

    def handle_status(self):
        try:
            available = readers()
            if not available:
                payload = {
                    "online": True,
                    "version": __version__,
                    "reader_connected": False,
                    "reader_name": None,
                    "card_present": False,
                    "message": "No smart card reader found. Connect ACR39U via USB."
                }
            else:
                reader = available[0]
                card_present = False
                try:
                    conn = reader.createConnection()
                    conn.connect(SCARD_SHARE_SHARED)
                    card_present = True
                    conn.disconnect()
                except Exception:
                    card_present = False

                payload = {
                    "online": True,
                    "version": __version__,
                    "reader_connected": True,
                    "reader_name": str(reader),
                    "card_present": card_present,
                    "message": "Reader ready" if card_present else "Reader connected. Please insert MyKad."
                }
            body = json.dumps(payload).encode("utf-8")
            self.send_response(200)
            self._send_cors_headers()
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        except Exception as e:
            err_body = json.dumps({"online": True, "version": __version__, "error": str(e)}).encode("utf-8")
            self.send_response(500)
            self._send_cors_headers()
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(err_body)))
            self.end_headers()
            self.wfile.write(err_body)

    def handle_read(self):
        try:
            available = readers()
            if not available:
                body = json.dumps({
                    "success": False,
                    "error": "No smart card reader detected. Please plug in the ACR39U."
                }).encode("utf-8")
                self.send_response(400)
                self._send_cors_headers()
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)
                return

            reader = available[0]
            conn = reader.createConnection()
            try:
                conn.connect()
            except (NoCardException, CardConnectionException):
                body = json.dumps({
                    "success": False,
                    "error": "No card inserted. Please insert your MyKad firmly into the reader."
                }).encode("utf-8")
                self.send_response(400)
                self._send_cors_headers()
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)
                return

            session = MyKadReaderSession(conn)
            data = session.read_mykad_data()
            conn.disconnect()

            body = json.dumps({
                "success": True,
                "data": data
            }).encode("utf-8")
            self.send_response(200)
            self._send_cors_headers()
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        except Exception as e:
            err_body = json.dumps({
                "success": False,
                "error": f"Failed to read MyKad: {str(e)}"
            }).encode("utf-8")
            self.send_response(500)
            self._send_cors_headers()
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(err_body)))
            self.end_headers()
            self.wfile.write(err_body)

    def log_message(self, format, *args):
        log.info(format, *args)


class BridgeHTTPServer(ThreadingHTTPServer):
    daemon_threads = True
    # On Windows SO_REUSEADDR lets a second process bind the same port. Claim it exclusively instead.
    allow_reuse_address = sys.platform != "win32"

    def server_bind(self):
        if sys.platform == "win32":
            self.socket.setsockopt(socket.SOL_SOCKET, socket.SO_EXCLUSIVEADDRUSE, 1)
        super().server_bind()


def run():
    setup_logging()
    try:
        httpd = BridgeHTTPServer((HOST, PORT), BridgeHTTPHandler)
    except OSError as e:
        if e.errno in (errno.EADDRINUSE, getattr(errno, "WSAEADDRINUSE", None)) or getattr(e, "winerror", None) in (10048, 10013):
            # Another copy already serves the port. Exit 0 so launchd (KeepAlive) does not respawn us.
            log.info("Port %s is already in use. Another bridge is running; exiting.", PORT)
            sys.exit(0)
        raise

    log.info("Estatell MyKad bridge %s listening on http://%s:%s (log: %s)", __version__, HOST, PORT, log_path())
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        log.info("Stopping...")
        httpd.server_close()

if __name__ == "__main__":
    run()

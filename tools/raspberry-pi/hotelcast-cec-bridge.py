#!/usr/bin/env python3
"""HotelCast web player -> HDMI-CEC bridge (Raspberry Pi, installed by tools/raspberry-pi/setup.sh).

Listens on 127.0.0.1 only (port HC_CEC_PORT, default 8765). The web player, opened with ?cec=<port>,
calls it when the screen goes off (SCREEN_OFF command, room switched off, "TV off" schedule) or on again:

    GET /on      TV on + make the Pi the active source   (cec-client: "on 0", "as")
    GET /off     TV standby                              (cec-client: "standby 0")
    GET /status  TV power status as reported over CEC    (cec-client: "pow 0")

Answers carry CORS + Private-Network-Access headers so Chromium allows the call from the player page.
Repeated identical requests within 10 s are ignored (the player may report the same state twice).
"""
import http.server
import os
import subprocess
import sys
import threading
import time

PORT = int(os.environ.get("HC_CEC_PORT", "8765"))
TV = os.environ.get("HC_CEC_TV", "0")  # logical address of the TV
LOCK = threading.Lock()
LAST = {"cmd": None, "at": 0.0}


def cec(*commands):
    """Run cec-client once with the given commands; returns (ok, output)."""
    script = "".join(c + "\n" for c in commands)
    try:
        r = subprocess.run(["cec-client", "-s", "-d", "1"], input=script.encode(), capture_output=True, timeout=25)
        return r.returncode == 0, r.stdout.decode("utf-8", "replace")
    except FileNotFoundError:
        return False, "cec-client not installed (apt install cec-utils)"
    except subprocess.TimeoutExpired:
        return False, "cec-client timed out"


class Handler(http.server.BaseHTTPRequestHandler):
    server_version = "HotelCastCEC/1.0"

    def _send(self, code, body=""):
        data = body.encode()
        self.send_response(code)
        self.send_header("Content-Type", "text/plain; charset=utf-8")
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, OPTIONS")
        self.send_header("Access-Control-Allow-Private-Network", "true")
        self.end_headers()
        if data:
            self.wfile.write(data)

    def do_OPTIONS(self):  # CORS / Private Network Access preflight
        self._send(204)

    def do_GET(self):
        path = self.path.split("?", 1)[0].rstrip("/")
        if path == "/status":
            ok, out = cec("pow " + TV)
            state = "on" if "power status: on" in out else "standby" if "standby" in out else "unknown"
            self._send(200 if ok else 503, state + "\n")
            return
        if path not in ("/on", "/off"):
            self._send(404, "use /on, /off or /status\n")
            return
        with LOCK:
            now = time.time()
            if LAST["cmd"] == path and now - LAST["at"] < 10:
                self._send(200, "ok (repeat ignored)\n")
                return
            LAST["cmd"], LAST["at"] = path, now
        ok, out = cec("on " + TV, "as") if path == "/on" else cec("standby " + TV)
        sys.stderr.write("CEC %s -> %s\n" % (path, "ok" if ok else out.strip()[-200:]))
        self._send(200 if ok else 503, ("ok" if ok else "failed") + "\n")

    def log_message(self, fmt, *args):
        sys.stderr.write("%s %s\n" % (self.address_string(), fmt % args))


def main():
    srv = http.server.ThreadingHTTPServer(("127.0.0.1", PORT), Handler)
    sys.stderr.write("HotelCast CEC bridge on 127.0.0.1:%d\n" % PORT)
    srv.serve_forever()


if __name__ == "__main__":
    main()

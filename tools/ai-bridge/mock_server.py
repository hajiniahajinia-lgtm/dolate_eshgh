"""Local-only prototype for the Dolate Eshgh AI bridge.

This is a mock service: it has NO database connection and cannot execute SQL.
It binds to 127.0.0.1 by default and requires AI_BRIDGE_TOKEN.
"""
from http.server import BaseHTTPRequestHandler, HTTPServer
import hmac
import json
import os
from urllib.parse import urlparse

HOST = os.environ.get("AI_BRIDGE_HOST", "127.0.0.1")
PORT = int(os.environ.get("AI_BRIDGE_PORT", "8787"))
TOKEN = os.environ.get("AI_BRIDGE_TOKEN", "")

TOOLS = [
    {
        "name": "health_check",
        "description": "Check mock bridge status; does not access the database.",
        "read_only": True,
    },
    {
        "name": "list_allowed_tables",
        "description": "Mock only. Real table listing is not implemented.",
        "read_only": True,
    },
]


class Handler(BaseHTTPRequestHandler):
    server_version = "DolateEshghAIBridgeMock/0.1"

    def _send(self, status, payload):
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

    def _authorized(self):
        supplied = self.headers.get("Authorization", "")
        expected = "Bearer " + TOKEN
        return bool(TOKEN) and hmac.compare_digest(supplied, expected)

    def do_GET(self):
        if not self._authorized():
            self._send(401, {"error": "unauthorized"})
            return

        path = urlparse(self.path).path
        if path == "/health":
            self._send(200, {
                "status": "ok",
                "mode": "mock",
                "database_connected": False,
                "sql_execution_enabled": False,
            })
        elif path == "/tools":
            self._send(200, {"tools": TOOLS})
        else:
            self._send(404, {"error": "not_found"})

    def do_POST(self):
        # No write routes or arbitrary SQL endpoint exist in this prototype.
        if not self._authorized():
            self._send(401, {"error": "unauthorized"})
            return
        self._send(404, {
            "error": "not_found",
            "message": "No POST operations are enabled in the mock prototype.",
        })

    def log_message(self, fmt, *args):
        # Avoid logging authorization headers or request payloads.
        print("%s - %s" % (self.address_string(), fmt % args))


if __name__ == "__main__":
    if not TOKEN:
        raise SystemExit("Set AI_BRIDGE_TOKEN to a long random secret before starting.")
    print(f"Starting mock bridge at http://{HOST}:{PORT} (database access disabled)")
    HTTPServer((HOST, PORT), Handler).serve_forever()

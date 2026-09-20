#!/usr/bin/env python3
import json
import os
import ssl
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse


MEDIA_PATH = os.environ.get("STASHD_FIXTURE_MEDIA_PATH", "/fixture/media.bin")
VIDEO_ID = "goldenvid01"


def media():
    with open(MEDIA_PATH, "rb") as handle:
        return handle.read()


def player_response():
    value = media()

    return {
        "playabilityStatus": {"status": "OK"},
        "videoDetails": {"videoId": VIDEO_ID, "title": "Golden Path Video", "shortDescription": "Golden path fixture"},
        "streamingData": {"formats": [{"url": f"https://www.youtube.com/videoplayback/{VIDEO_ID}", "mimeType": "video/mp4", "qualityLabel": "360p", "contentLength": str(len(value))}]},
    }


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        path = urlparse(self.path)
        query = parse_qs(path.query)

        if path.path == "/oembed":
            self.send_json({"title": "Golden Path Video"})
        elif path.path == "/watch":
            self.send_html(
                '<html><head><meta property="og:title" content="Golden Path Video"></head>'
                '<body><script>var ytInitialPlayerResponse = '
                + json.dumps(player_response())
                + ';</script></body></html>'
            )
        elif path.path == f"/videoplayback/{VIDEO_ID}":
            self.send_bytes("video/mp4", media())
        elif path.path == f"/vi/{VIDEO_ID}/hqdefault.jpg":
            self.send_bytes("image/jpeg", b"golden-path-thumbnail\n")
        else:
            self.send_error(404)

    def do_POST(self):
        if urlparse(self.path).path.startswith("/youtubei/v1/"):
            self.send_json(player_response())
            return

        self.send_error(404)

    def send_json(self, value):
        self.send_bytes("application/json", json.dumps(value).encode())

    def send_html(self, value):
        self.send_bytes("text/html", value.encode())

    def send_bytes(self, content_type, value):
        self.send_response(200)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(value)))
        self.end_headers()
        self.wfile.write(value)


if __name__ == "__main__":
    server = ThreadingHTTPServer(("0.0.0.0", 443), Handler)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain("/fixture/cert.pem", "/fixture/key.pem")
    server.socket = context.wrap_socket(server.socket, server_side=True)
    server.serve_forever()

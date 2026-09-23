#!/usr/bin/env python3
import json
import os
import ssl
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse


MEDIA_PATH = os.environ.get("STASHD_FIXTURE_MEDIA_PATH", "/fixture/media.bin")
RETRY_MEDIA_PATH = os.environ.get("STASHD_FIXTURE_RETRY_MEDIA_PATH", "/fixture/retry-media.bin")
RETRY_MODE_PATH = os.environ.get("STASHD_FIXTURE_RETRY_MODE_PATH", "/fixture/retry-mode")
CAPTION_MODE_PATH = os.environ.get("STASHD_FIXTURE_CAPTION_MODE_PATH", "/fixture/caption-mode")
ESTIMATE_MEDIA_PATH = os.environ.get("STASHD_FIXTURE_ESTIMATE_MEDIA_PATH", "/fixture/estimate-media.bin")
VIDEO_ID = "goldenvid01"
RETRY_VIDEO_ID = "retryfail01"
ESTIMATE_VIDEO_IDS = {"estimate001", "estimate002"}
CAPTION = b"WEBVTT\n\n00:00.000 --> 00:01.000\nDeterministic fixture caption\n"
HELPER_INVOCATIONS = 0


def media(path=MEDIA_PATH):
    with open(path, "rb") as handle:
        return handle.read()


def retry_is_healthy():
    with open(RETRY_MODE_PATH, "r", encoding="utf-8") as handle:
        return handle.read().strip() == "healthy"


def caption_is_healthy():
    try:
        with open(CAPTION_MODE_PATH, "r", encoding="utf-8") as handle:
            return handle.read().strip() == "healthy"
    except FileNotFoundError:
        return True


def player_response(video_id=VIDEO_ID, title="Golden Path Video", value=None):
    value = media(ESTIMATE_MEDIA_PATH) if value is None and video_id in ESTIMATE_VIDEO_IDS else value
    value = media() if value is None and video_id == VIDEO_ID else value
    if value is None:
        value = media(RETRY_MEDIA_PATH)

    response = {
        "playabilityStatus": {"status": "OK"},
        "videoDetails": {"videoId": video_id, "title": title, "shortDescription": "Golden path fixture"},
        "streamingData": {"formats": [{"url": f"https://www.youtube.com/videoplayback/{video_id}", "mimeType": "video/mp4", "qualityLabel": "360p", "contentLength": str(len(value))}]},
    }
    if video_id == VIDEO_ID and caption_is_healthy():
        response["captions"] = {"playerCaptionsTracklistRenderer": {"captionTracks": [{
            "baseUrl": "https://www.youtube.com/api/timedtext?v=goldenvid01&lang=en&fmt=vtt",
            "languageCode": "en",
            "name": {"simpleText": "English"},
            "kind": "",
        }]}}
    return response


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        path = urlparse(self.path)
        query = parse_qs(path.query)

        if path.path == "/oembed":
            self.send_json({"title": "Golden Path Video"})
        elif path.path == "/playlist":
            self.send_html('<meta property="og:title" content="Estimator Playlist">')
        elif path.path == "/youtube/v3/playlistItems":
            video_id = "estimate001" if query.get("playlistId") == ["PLSizeBootstrap"] else "estimate002"
            self.send_json({"items": [{"snippet": {"resourceId": {"videoId": video_id}, "title": "Estimator proof " + video_id}}], "nextPageToken": None})
        elif path.path == "/youtube/v3/videos":
            requested = query.get("id", [""])[0].split(",")
            self.send_json({"items": [{
                "id": video_id,
                "snippet": {"title": "Estimator proof " + video_id, "publishedAt": "2026-01-01T00:00:00Z"},
                "contentDetails": {"duration": "PT181S", "definition": "hd"},
            } for video_id in requested if video_id in ESTIMATE_VIDEO_IDS]})
        elif path.path == "/fixture/yt-dlp-count":
            self.send_json({"count": HELPER_INVOCATIONS})
        elif path.path == "/watch":
            video_id = query.get("v", [VIDEO_ID])[0]
            self.send_html(
                '<html><head><meta property="og:title" content="Golden Path Video"></head>'
                '<body><script>var ytInitialPlayerResponse = '
                + json.dumps(player_response(video_id))
                + ';</script></body></html>'
            )
        elif path.path == f"/videoplayback/{VIDEO_ID}":
            self.send_bytes("video/mp4", media())
        elif path.path == "/api/timedtext" and query.get("v") == [VIDEO_ID] and query.get("lang") == ["en"] and query.get("fmt") == ["vtt"]:
            if not caption_is_healthy():
                self.send_error(503, "captions are deliberately unavailable")
                return
            self.send_bytes("text/vtt", CAPTION)
        elif path.path == f"/videoplayback/{RETRY_VIDEO_ID}":
            if not retry_is_healthy():
                self.send_error(503, "retry fixture is deliberately broken")
                return
            self.send_bytes("video/mp4", media(RETRY_MEDIA_PATH))
        elif path.path.startswith("/videoplayback/") and path.path.rsplit("/", 1)[-1] in ESTIMATE_VIDEO_IDS:
            self.send_bytes("video/mp4", media(ESTIMATE_MEDIA_PATH))
        elif path.path == f"/vi/{VIDEO_ID}/hqdefault.jpg":
            self.send_bytes("image/jpeg", b"golden-path-thumbnail\n")
        else:
            self.send_error(404)

    def do_POST(self):
        global HELPER_INVOCATIONS
        if urlparse(self.path).path == "/fixture/yt-dlp-invoked":
            HELPER_INVOCATIONS += 1
            self.send_json({"count": HELPER_INVOCATIONS})
            return

        if urlparse(self.path).path.startswith("/youtubei/v1/"):
            body = self.rfile.read(int(self.headers.get("Content-Length", "0")))
            try:
                video_id = json.loads(body).get("videoId", VIDEO_ID)
            except (json.JSONDecodeError, AttributeError):
                video_id = VIDEO_ID
            self.send_json(player_response(video_id))
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

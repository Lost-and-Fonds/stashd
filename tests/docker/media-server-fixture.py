#!/usr/bin/env python3
import json
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse


provider = sys.argv[1]
root = Path('/fixture')
token = 'golden-media-server-token'


def record(handler, status):
    with (root / 'requests.jsonl').open('a', encoding='utf-8') as output:
        json.dump({
            'method': handler.command,
            'path': urlparse(handler.path).path,
            'query': parse_qs(urlparse(handler.path).query),
            'headers': {key.lower(): value for key, value in handler.headers.items()},
            'status': status,
        }, output)
        output.write('\n')


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        path = urlparse(self.path).path
        query = parse_qs(urlparse(self.path).query)
        if provider == 'jellyfin':
            if self.headers.get('X-Emby-Token') != token:
                return self.reply(401, b'')
            if path == '/System/Info/Public':
                return self.reply(200, b'{"ServerName":"Fixture Jellyfin","Version":"10.9.0"}', 'application/json')
            if path == '/Library/MediaFolders':
                return self.reply(200, b'{"Items":[{"Id":"fixture-library","Name":"Fixture Library","CollectionType":"tvshows"}]}', 'application/json')
            if path == '/Library/Refresh':
                return self.reply(503 if (root / 'refresh-fail').exists() else 204, b'')
        if provider == 'plex':
            if query.get('X-Plex-Token', [''])[0] != token:
                return self.reply(401, b'')
            if path == '/identity':
                return self.reply(200, b'<MediaContainer size="0" machineIdentifier="fixture" version="1.0" />', 'application/xml')
            if path == '/library/sections':
                return self.reply(200, b'<MediaContainer><Directory key="fixture-library" title="Fixture Library" type="show" /></MediaContainer>', 'application/xml')
            if path == '/library/sections/fixture-library/refresh':
                return self.reply(503 if (root / 'refresh-fail').exists() else 200, b'')
        self.reply(404, b'')

    def do_POST(self):
        self.do_GET()

    def reply(self, status, body, content_type='text/plain'):
        record(self, status)
        self.send_response(status)
        self.send_header('Content-Type', content_type)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        if body:
            self.wfile.write(body)

    def log_message(self, *_):
        return


ThreadingHTTPServer(('0.0.0.0', 80), Handler).serve_forever()

#!/bin/sh
set -eu

youtube=https://www.youtube.com
output=''
cookies=''
extractor_args=''
reference=''
audio=0
captions=0
metadata=0
artwork=0
skip_download=0

fetch() {
    url=$1
    shift
    if ! curl --connect-timeout 2 --max-time 5 -fsS "$url" "$@"; then
        printf '%s' "$url" | curl --connect-timeout 2 --max-time 5 -fsS -X POST --data-binary @- "$youtube/fixture/yt-dlp-error" >/dev/null || true
        exit 22
    fi
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --output) output=$2; shift 2 ;;
        --cookies) cookies=$2; shift 2 ;;
        --extractor-args) extractor_args=$2; shift 2 ;;
        --audio-format) audio=1; shift 2 ;;
        --write-subs) captions=1; shift ;;
        --write-info-json) metadata=1; shift ;;
        --write-thumbnail) artwork=1; shift ;;
        --skip-download) skip_download=1; shift ;;
        *) reference=$1; shift ;;
    esac
done

[ -n "$output" ] && [ -n "$reference" ] || exit 2
[ -f "$cookies" ] && [ "$(stat -c '%a' "$cookies")" = 600 ] || exit 3
grep -Fxq 'youtube-golden-cookie-fixture' "$cookies" || exit 4
[ "$extractor_args" = 'youtube:po_token=web.gvs+golden-po-token' ] || exit 5

if [ "$skip_download" -eq 0 ]; then
    printf 'download:progress=5%%;total=NA;estimate=NA\n' >&2
    printf 'download:progress=20%%;total=NA;estimate=1048576\n' >&2
    sleep 2
fi
curl --connect-timeout 2 --max-time 5 -fsS -X POST "$youtube/fixture/yt-dlp-invoked" >/dev/null

video_id=$(printf '%s' "$reference" | sed -n 's/.*[?&]v=\([^&]*\).*/\1/p')
[ -n "$video_id" ] || exit 6
prefix=${output%.*}

if [ "$audio" -eq 1 ]; then
    extension=mp3
else
    extension=mp4
fi

if [ "$captions" -eq 1 ]; then
    caption_path="$prefix.en.vtt"
    fetch "$youtube/api/timedtext?v=$video_id&lang=en&fmt=vtt" -o "$caption_path"
    printf '%s\n' "$caption_path"
fi

if [ "$metadata" -eq 1 ]; then
    metadata_path="$prefix.info.json"
    printf '{"id":"%s"}\n' "$video_id" > "$metadata_path"
    printf '%s\n' "$metadata_path"
fi

if [ "$artwork" -eq 1 ]; then
    artwork_path="$prefix.jpg"
    curl --connect-timeout 2 --max-time 5 -fsS "$youtube/vi/$video_id/hqdefault.jpg" -o "$artwork_path" || true
    if [ -f "$artwork_path" ]; then printf '%s\n' "$artwork_path"; fi
fi

if [ "$skip_download" -eq 0 ]; then
    primary_path="$prefix.$extension"
    fetch "$youtube/videoplayback/$video_id" -o "$primary_path"
    total=$(wc -c < "$primary_path" | tr -d ' ')
    printf 'download:progress=95%%;total=%s;estimate=NA\n' "$total" >&2
    sleep 2
    printf '%s\n' "$primary_path"
fi

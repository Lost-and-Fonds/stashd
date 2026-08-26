#!/bin/sh
cd /staging

url=""
for argument in "$@"; do
    url="$argument"
done

case "$url" in
    *fail-acquisition*)
        echo "fixture helper failure" >&2
        exit 1
        ;;
esac

printf 'stub-ytdlp-media\nurl=%s\n' "$url" > youtube-stashd-original.mp4
printf '{"id":"fixture-video","title":"Fixture Video"}\n' > youtube-stashd-original.info.json
printf 'fixture thumbnail\n' > youtube-stashd-original.jpg

case " $* " in
    *" --write-subs "*)
        printf 'WEBVTT\n\n00:00.000 --> 00:01.000\nFixture caption\n' > youtube-stashd-original.en.vtt
        ;;
esac

printf '%s\n' "$(pwd)/youtube-stashd-original.mp4"
printf '%s\n' "$(pwd)/youtube-stashd-original.info.json"
printf '%s\n' "$(pwd)/youtube-stashd-original.jpg"

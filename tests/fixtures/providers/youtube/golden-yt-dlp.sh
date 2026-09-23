#!/bin/sh
set -eu

curl -fsS -X POST 'https://www.youtube.com/fixture/yt-dlp-invoked' >/dev/null

case " $* " in
    *estimate001*" --format "*)
        printf 'download:progress=10.0%%;total=NA;estimate=1048576\n' >&2
        sleep 2
        printf 'download:progress=20.0%%;total=1048576;estimate=NA\n' >&2
        sleep 2
        ;;
esac

exec /plugin/stashd-plugin/helpers/yt-dlp-real "$@"

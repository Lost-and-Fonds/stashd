# Stashd Podcast plugin

The bundled Podcast plugin owns its native media helper. The current Linux
helper is the GPL build from BtbN's FFmpeg-Builds release:

```text
release: autobuild-2026-08-21-13-40
build:   N-126239-g88ae625e69
```

The package build downloads immutable release assets and verifies them before
placing `ffmpeg` under `helpers/`:

| architecture | asset | SHA-256 |
| --- | --- | --- |
| amd64 | `ffmpeg-N-126239-g88ae625e69-linux64-gpl.tar.xz` | `b2ad9015c296a61c1f6127c4aa3ce8614a9bd8d7519987b6e0d151edaa7f39fb` |
| arm64 | `ffmpeg-N-126239-g88ae625e69-linuxarm64-gpl.tar.xz` | `59f9c4258284fa750b025939b210aa51a9cb8b6411d4a417f332facc2d0d2df2` |

The helper is resolved from the installed plugin package. Stashd core does
not install, configure, or invoke FFmpeg.

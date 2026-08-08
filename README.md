# MediaWeb

PHP tool that scans video directories, extracts metadata with MediaInfo, stores it in SQLite, and serves a small web library for browsing and playback.

## Features

- Incremental directory scans (new files only by default)
- MediaInfo → SQLite catalog (`media.db`)
- Web library with search, thumbnails, and play counts
- Browser playback via movi-player; **avbridge** (libav WASM) for files flagged `needs_fix`
- Optional packed-B-frame AVI→MKV remux (`-c copy`) in fixable dirs
- Soft-delete tracking when files disappear between scans

## Requirements

- PHP (CLI + Apache/`mod_php` or equivalent) with SQLite3
- [MediaInfo](https://mediaarea.net/en/MediaInfo) CLI
- `ffmpeg` / `ffprobe` (set absolute paths in config — may not be on the web user’s `PATH`)
- HTTP byte-range support on video URL prefixes (`Accept-Ranges: bytes`)

## Quick start

```bash
cp config.example.php config.php
# Edit MW_FFMPEG, MW_FFPROBE, MW_BASE_URL, MW_FIXABLE_DIRS, MW_ACTIVE_DIRS

php scan.php --verbose
php list.php --limit=20
```

Point Apache (or similar) at `web/` under your chosen `MW_BASE_URL`. Map each dir’s `url` prefix to its `fs` tree so the player can fetch media.

Ensure `web/covers/` is writable by the web user.

## CLI

```bash
php scan.php --help
php scan.php --verbose                 # incremental scan
php scan.php --force-rescan            # re-extract metadata for known files
php scan.php --scan-only --verbose     # refresh needs_fix (PTS / remux probe)
php scan.php --fix-broken-avi --verbose # optional packed-B remux → .fixed.mkv

php list.php --format=HEVC
php list.php --name="search" --limit=20
php list.php --count --format=AVC
php list.php --columns=filename,width,height,duration_secs,needs_fix
```

**Default scan:** skip files already in the DB; probe new ones; mark missing rows `is_deleted=1` (hidden from the library, kept for play counts).

## Config

Shared constants live in `config.php` (gitignored). Start from `config.example.php`:

| Constant | Role |
|----------|------|
| `MW_DB` | SQLite path (default `./media.db`) |
| `MW_BASE_URL` | Web app URL prefix (e.g. `/mweb/`) |
| `MW_FFMPEG` / `MW_FFPROBE` | Absolute binary paths |
| `MW_FIXABLE_DIRS` | Dirs where optional AVI remux is allowed |
| `MW_ACTIVE_DIRS` | Read-only / torrent-managed dirs (never remuxed) |

Each storage entry is independent: `[ 'fs' => '/path/on/disk', 'url' => '/apache_prefix/' ]`. Only the URL prefixes need to be public.

## Browser playback and `needs_fix`

Some older AVI / MPEG-4 Part 2 (Xvid/DivX) files play fine in VLC but jitter or fail in a browser demuxer. MediaWeb marks those after a **PTS / remux probe** (`needs_fix=1`) — codec alone is not enough.

| Situation | Approach |
|-----------|----------|
| Packed B-frames | Optional `--fix-broken-avi` (AVI→MKV, `mpeg4_unpack_bframes`) |
| Bad / missing PTS | Client-side **avbridge** software decode (not server re-encode) |

The library shows **[!]** for flagged titles. The player page uses avbridge for those (or `?player=avbridge`). Vendored under `web/vendor/avbridge/`.

Bulk server-side remux/re-encode to H.264 is intentionally out of scope.

## Layout

```
scan.php / list.php     CLI
config.example.php      template → config.php
database_schema.sql     videos table
web/
  index.php             router (library or view)
  library.php           search + grid
  view.php              player
  getcover.php          thumbnails
  increment-play.php    play-count AJAX
  vendor/avbridge/      WASM player fallback
  layout/               shared chrome
```

## License

Use as you like for personal / local library hosting.
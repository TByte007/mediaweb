# Project Rules

- PHP is the base language for this project
- Ask for ideas before writing a lot of code for new functionality (prefer discussing approach up front)
- Shared config in `config.php` — use its constants (`MW_DB`, `MW_BASE_URL`, `MW_FFMPEG`, etc.)
- Never log secrets

# MediaWeb

CLI tool that scans video directories, extracts metadata with MediaInfo, and stores it in SQLite.

Web interface at http://titan.voltage.nz/mweb/ → `web/` directory.

## Essential commands

```bash
php scan.php --help
php scan.php --force-rescan              # full metadata rescan (after major changes)
php scan.php --scan-only --verbose       # PTS probe → needs_fix (browser warning)
php scan.php --series-backfill           # re-link series/seasons/episodes only
php list.php --format=HEVC
php list.php --name="search" --limit=20
php list.php --count --format=AVC
php list.php --columns=filename,width,height,duration_secs,needs_fix
```

**Default behavior:** Skips files already in DB (fast incremental scans). New files get MediaInfo + PTS/`needs_fix` detect. Missing files marked as `is_deleted=1`. Use `--force-rescan` to re-extract metadata; `--scan-only` to refresh `needs_fix` on candidates.

**Series linking:** After each normal scan (not `--scan-only`), `linkSeries()` in [`series.php`](series.php) parses season/episode from paths, upserts the `series` table, and sets `videos.series_id`. Use `php scan.php --series-backfill` to run only that pass (no tree walk / MediaInfo).

## Config (`config.php`)

- `MW_DB`: database path (`./media.db`)
- `MW_BASE_URL`: `/mweb/`
- `MW_FFMPEG`: `/usr/local/bin/ffmpeg` (not on www's PATH)
- `MW_MEDIA_DIRS`: media roots `[ ['fs' => filesystem_path, 'url' => apache_url_prefix], ... ]`

  Example:
  ```php
  MW_MEDIA_DIRS = [
    ['fs' => '/fstore2/torrents/notorrent', 'url' => '/notor/'],
    ['fs' => '/fstore2/torrents/download', 'url' => '/act_tor/'],
  ];
  ```
  Directories are independent; not tied to a parent path. Only the exposed `url` prefixes are publicly accessible.

## Media roots

| Dir | URL prefix |
|-----|------------|
| `/fstore2/torrents/notorrent` | `/notor/` |
| `/fstore2/torrents/download` | `/act_tor/` |

## AVI / MPEG-4 Part 2 and browser playback

Bad or missing PTS often plays fine in VLC/WMP (they invent timing) but jitters in browser demuxers. Stream-copy cannot restore real PTS. MediaWeb marks those files and plays them with **avbridge**.

Packed B-frames (DivX/Xvid) are handled at play time by avbridge’s `mpeg4_unpack_bframes` BSF — not by server-side remux.

**Do not propose server-side remux/re-encode to H.264** (or any bulk transcode) to “fix” `needs_fix` / browser playback. Not feasible here — treat as out of scope. Client-side playback (avbridge) only.

### `needs_fix` = browser warning after a PTS test (mark only)

Candidates (not every video):

- `.avi`
- MPEG-4 Part 2 / Xvid / DivX in any container (`MPEG-4 Visual`, `XVID`, …)
- **Not** H.264 (`V_MPEG4/ISO/AVC` / Format `AVC`)

**Test** (must fail to set the flag — codec alone is not enough):

1. short `ffmpeg -t 5 -i file -c copy` probe fails, or
2. ≥10% of sampled frames have `pts_time=N/A`, or
3. ≥10% of sampled video packets have backwards DTS (non-monotonic; probe may still exit 0)

```bash
php scan.php --scan-only --verbose
```

Web UI: **[!]** in the library grid; player page uses **avbridge** (libav WASM
software decode) for `needs_fix=1` instead of movi-player, with download+VLC as backup.

### Browser player (avbridge fallback)

Vendored under `web/vendor/avbridge/` (npm `avbridge` 2.13 — `player-browser.js` +
`vendor/libav`; `element-browser.js` kept but unused by the view). For `needs_fix=1`
(or `?player=avbridge`), `view.php` loads `<avbridge-player preferstrategy="fallback">`
(upstream chrome wrapping `<avbridge-video>`) with `AVBRIDGE_LIBAV_BASE` pointed at the
local libav tree. Needs HTTP Range on `/notor/` and `/act_tor/` (Apache already sends
`Accept-Ranges: bytes`).

**Firefox:** `drawImage(I420 VideoFrame)` corrupts software YUV frames. Vendored
`player-browser.js` converts YUV→RGBA via libav **swscale** (Wasm) on Firefox only,
before `laFrameToVideoFrame`. Do not reintroduce a JS pixel-loop or async
`copyTo`/`drawImage` monkey-patch (races with paint→`close()`). Not a reason to
suggest server H.264 re-encode.

Rebuild player bundle (Node once; not needed on TiTaN at runtime):

```bash
cd tmp/avbridge-player-build   # or fresh: npm i avbridge@2.13.0 esbuild
node ../../web/vendor/avbridge/patches/apply-firefox-swscale.mjs
node ../../web/vendor/avbridge/patches/disable-calib-resnap.mjs
npx esbuild node_modules/avbridge/dist/player.js --bundle --format=esm --platform=browser \
  --outfile=../../web/vendor/avbridge/dist/player-browser.js
```

## Deleted-file tracking

Videos marked `is_deleted=1` when their file disappears between scans. They stay in the DB (playback counts kept) but are hidden from the library.

Purge with: `DELETE FROM videos WHERE is_deleted=1;`

## Series browse

Web: `?mode=series` → shows → `?mode=series&sid=N` → seasons → `&season=N` → episodes (binge order). Flat Library still lists episodes.

Detection (per top-level folder under a media root): ≥2 season-like child dirs with videos, **or** ≥5 files with a parseable episode. Columns: `series` (`root_key`, `title`, `cover_video_id`); on `videos`: `series_id`, `season`, `episode`, `episode_title`.

## Web structure

```
web/index.php          → router (library, series mode, or video view)
web/library.php        → search + video grid (excludes is_deleted=1, shows [!] for needs_fix=1)
web/series.php         → Series mode: shows → seasons → episodes
series.php             → parseEpisodeFields / linkSeries (used by scan + web)
web/view.php → video player (movi-player normally; avbridge-player for needs_fix=1)
web/vendor/avbridge/ → avbridge player-browser + libav WASM (lazy-loaded)
web/getcover.php       → cached non-black thumbnail generation
web/increment-play.php → AJAX endpoint for playback count
web/layout/*           → shared layout components
```

**URL mappings:**

Defined per directory in config.php (`MW_MEDIA_DIRS`). Each dir entry has:

- `fs`: filesystem root path
- `url`: Apache URL prefix

Example:

| Filesystem | Apache path |
|------------|-------------|
| `/fstore2/torrents/notorrent/...` | `/notor/...` |
| `/fstore2/torrents/download/...` | `/act_tor/...` |

This design is intentionally decoupled from the storage root; you can point URL prefixes to completely different filesystem trees without exposing the parent hierarchy.

## Gotchas (discovered in production)

- Full scan takes ~15-20 min for ~3000+ files; design commands to be background-friendly
- Directory has permission-denied folders → use `@opendir` + safe recursion
- `fetchArray(SQLite3::NUM)` is broken on this BSD setup → use `2` for numeric mode
- Covers directory `web/covers/` must be writable (0777) by Apache `www` user
- **ffmpeg not on www user's PATH!** → Use `/usr/local/bin/ffmpeg` explicitly (`MW_FFMPEG` constant)
- Many video directories already contain `cover.jpg` / `thumb.jpg` files → `getcover.php` checks these first before extracting frames
- **Never suggest server remux/re-encode to H.264** to fix browser playback / `needs_fix` — not feasible; use avbridge + Firefox swscale→RGBA in `player-browser.js`

## FreeBSD notes

- `sed -i` requires an empty backup suffix argument: `sed -i '' 's/old/new/g' file`
- This is different from GNU sed on Linux; the empty string means "don't create a backup file"

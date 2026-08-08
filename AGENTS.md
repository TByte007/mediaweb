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
php scan.php --fix-broken-avi --verbose  # optional packed-B-frame remux (does not clear needs_fix)
php list.php --format=HEVC
php list.php --name="search" --limit=20
php list.php --count --format=AVC
php list.php --columns=filename,width,height,duration_secs,needs_fix
```

**Default behavior:** Skips files already in DB (fast incremental scans). New files get MediaInfo + AVI remux probe. Missing files marked as `is_deleted=1`. Use `--force-rescan` to re-extract metadata; `--scan-only` to refresh `needs_fix` on all AVIs.

## Config (`config.php`)

- `MW_DB`: database path (`./media.db`)
- `MW_BASE_URL`: `/mweb/`
- `MW_FFMPEG`: `/usr/local/bin/ffmpeg` (not on www's PATH)
- `MW_FIXABLE_DIRS` / `MW_ACTIVE_DIRS`: structured arrays `[ ['fs' => filesystem_path, 'url' => apache_url_prefix], ... ]`

  Example:
  ```php
  MW_FIXABLE_DIRS = [ ['fs' => '/fstore2/torrents/notorrent', 'url' => '/notor/'] ];
  MW_ACTIVE_DIRS  = [ ['fs' => '/fstore2/torrents/download', 'url' => '/act_tor/'] ];
  ```
  Directories are independent; not tied to a parent path. Only the exposed `url` prefixes are publicly accessible.

## Storage tiers

| Tier | Dir | URL prefix | Behavior |
|------|-----|------------|----------|
| Fixable | `/fstore2/torrents/notorrent` | `/notor/` | Optional packed-B-frame remux allowed; never clears PTS warnings |
| Active | `/fstore2/torrents/download` | `/act_tor/` | Managed by torrent client; never modified |

## AVI issues: packed B-frames vs bad PTS

Two different problems often get lumped together:

| Problem | What helps | Browser (movi-player) |
|---------|------------|------------------------|
| **Packed B-frames** (DivX/Xvid bitstream packing) | Remux AVI→MKV with `-bsf:v mpeg4_unpack_bframes` (`-c copy`) | Often better after unpack→MKV |
| **Bad/missing PTS** | Desktop demuxers invent timing (VLC/WMP). Stream-copy cannot restore real PTS | Still jitters in movi-player → use **avbridge** |

**Do not propose server-side remux/re-encode to H.264** (or any bulk transcode) to “fix” `needs_fix` / browser playback. Not feasible here — treat as out of scope. Client-side playback (avbridge) and optional packed-B-frame remux (`--fix-broken-avi`) only.

### `needs_fix` = browser warning after a PTS test (mark only)

Candidates (not every video):

- `.avi`
- MPEG-4 Part 2 / Xvid / DivX in any container (`MPEG-4 Visual`, `XVID`, …)
- **Not** H.264 (`V_MPEG4/ISO/AVC` / Format `AVC`)

**Test** (must fail to set the flag — codec alone is not enough):

1. `ffmpeg -t 5 -i file -c copy` remux fails, or
2. ≥10% of sampled frames have `pts_time=N/A`, or
3. ≥10% of sampled video packets have backwards DTS (non-monotonic; remux may still exit 0)

Many AVI→MKV remuxes pass both tests and stay `needs_fix=0`.

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
npx esbuild node_modules/avbridge/dist/player.js --bundle --format=esm --platform=browser \
  --outfile=../../web/vendor/avbridge/dist/player-browser.js
```

### `--fix-broken-avi` = optional packed-B-frame remux only

```bash
php scan.php --fix-broken-avi --verbose
# optional: --del-original
```

In fixable dirs (or `--dir=`): writes `name.fixed.mkv` via `-c copy -bsf:v mpeg4_unpack_bframes`.
Uses `+genpts` only as a **mux aid** when unpack remux otherwise fails. If the source had PTS
problems, `needs_fix` stays `1` on the new row.

## Deleted-file tracking

Videos marked `is_deleted=1` when their file disappears between scans. They stay in the DB (playback counts kept) but are hidden from the library.

Purge with: `DELETE FROM videos WHERE is_deleted=1;`

## Web structure

```
web/index.php          → router (library or video view)
web/library.php        → search + video grid (excludes is_deleted=1, shows [!] for needs_fix=1)
web/view.php → video player (movi-player normally; avbridge-player for needs_fix=1)
web/vendor/avbridge/ → avbridge player-browser + libav WASM (lazy-loaded)
web/getcover.php       → cached non-black thumbnail generation
web/increment-play.php → AJAX endpoint for playback count
web/layout/*           → shared layout components
```

**URL mappings:**

Defined per directory in config.php (`MW_FIXABLE_DIRS` and `MW_ACTIVE_DIRS`). Each dir entry has:

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

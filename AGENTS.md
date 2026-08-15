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
php scan.php --titles-backfill           # re-link + enrich titles (no tree walk)
php scan.php --titles-backfill --force   # refresh titles from cached TMDB ids + gaps
php scan.php --titles-backfill --no-tmdb --no-llm   # link + PHP fallback only (tests)
php list.php --format=HEVC
php list.php --name="search" --limit=20
php list.php --count --format=AVC
php list.php --columns=filename,width,height,duration_secs,needs_fix
php list.php --deleted
php users.php add NAME --role=admin|manager|viewer
php users.php list
```

**Default behavior:** Skips files already in DB (fast incremental scans). New files get MediaInfo + PTS/`needs_fix` detect. New paths that match a gone file (same `filesize`+`filename`, else unique `filesize`) adopt that row instead of INSERT (keeps plays / names / ids). Missing files marked as `is_deleted=1`. Use `--force-rescan` to re-extract metadata; `--scan-only` to refresh `needs_fix` on candidates (no link/enrich).

**Series linking + title enrich:** After each normal scan (not `--scan-only`), `linkSeries()` in [`series.php`](series.php) then `enrichTitles()` in [`titles.php`](titles.php): leftover unlinked TV-like files may attach to an existing `series` row via unique title key or LLM pick from a short DB list (CLI only; never on page views). Then **dirs/files → (LLM search terms) → TMDB → store id + canonical title + genre_ids + vote_average + poster_path + overview (+ TV `tmdb_type`)**, then LLM/PHP gap-fill for misses. `linkSeries` does not overwrite `series.title` when `tmdb_id` is set. Layers run when configured; skip with empty `MW_TMDB_TOKEN` / `MW_LLM_URL`, unreachable llama, or `--no-tmdb` / `--no-llm` (folder/heur search only without LLM). Details refresh for score/poster/overview runs **only when `tmdb_id` is set** (episodes: show id + `videos.tmdb_refreshed_at`); null stamp or weekly-ish age (hash jitter ± days) marks due. Junk dirs (`extras`, …) are not movie-searched. `--force` refreshes titles/meta from cached TMDB ids (does not re-search; not needed for first score fill). Web UI prefers `videos.name`; Library defaults to Movies (standalone ≥50m; `?len=clip` / `?len=all`); Library/Series support `?genre=` filter; Series cards show TV type; player shows score chip + plot overview when set. Covers: episode/movie `videos.poster_path` (episode = TMDB still); season posters in `series_seasons`; show poster on `series.poster_path`. Overviews: `series.overview` above the season grid, `series_seasons.overview` above the episode grid, `videos.overview` on the player. `getcover.php?id=` / `?sid=` / `?sid=&season=`. Never call TMDB API or the LLM on page views. Use `php scan.php --titles-backfill` to relink + enrich without a MediaInfo walk.

## Config (`config.php`)

- `MW_DB`: database path (`./media.db`)
- `MW_BASE_URL`: `/mweb/`
- `MW_SUBS_DIR`: overlay uploads (`./subs/{video_id}/`, not under `web/`)
- `MW_ALLOW_NETS`: CIDRs that skip login as viewer (`10.0.0.0/24`, `10.10.155.0/24`; empty = login wall)
- `MW_FFMPEG`: `/usr/local/bin/ffmpeg` (not on www's PATH)
- `MW_MEDIA_DIRS`: media roots `[ ['fs' => filesystem_path, 'url' => apache_url_prefix], ... ]`
- `MW_TMDB_TOKEN` / `MW_TMDB_MIN_SECS`: optional TMDB in enrich (empty token disables; min secs default 600)
- `MW_TMDB_REFRESH_DAYS` / `MW_TMDB_REFRESH_JITTER`: score/poster re-poll interval (default 7±3 days)
- `MW_LLM_URL` / `MW_LLM_MODEL` / `MW_LLM_TIMEOUT` / `MW_LLM_PARALLEL` / `MW_LLM_TEMPERATURE` / `MW_LLM_MAX_TOKENS` / `MW_LLM_THINKING`: optional llama-server in enrich (empty URL disables; parallel = concurrent chat requests, 1=serial; thinking off for Qwen3.8)

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

Videos marked `is_deleted=1` when their file disappears between scans. They stay in the DB (playback counts kept) but are hidden from the library. A later scan that finds the same bytes under a new path (move between media roots, or rename when size is unique) adopts the row and clears `is_deleted`.

Purge with: `DELETE FROM videos WHERE is_deleted=1;`

## Series browse

Web: `?mode=series` → shows → `?mode=series&sid=N` → seasons → `&season=N` → episodes (binge order). All (`?len=all`) still lists episodes in the flat grid; Movies/Clips exclude series-linked files.

Detection (per top-level folder under a media root): ≥2 season-like child dirs with videos, **or** ≥5 files with a parseable episode. Same-show season packs in different folders share one series row. Loose SxxExx / scene-code files (`bones.924.hdtv`) group by show name into a series even as one-offs. Columns: `series` (`root_key`, `title`, `cover_video_id`); on `videos`: `series_id`, `season`, `episode`, `episode_title`.

## Web structure

```
web/index.php          → router (library, series mode, or video view); login or MW_ALLOW_NETS guest
web/login.php          → session login / logout (LAN logout returns to library as Guest)
web/library.php        → search + video grid (default Movies ≥50m not series; `?len=clip`/`?len=all`; excludes is_deleted=1, shows [!] for needs_fix=1)
web/series.php         → Series mode: shows → seasons → episodes
series.php             → parseEpisodeFields / linkSeries (used by scan + web)
auth.php               → users table, session, roles (viewer / manager / admin); CIDR guest viewer
users.php              → CLI add / passwd / role / list / del
subs.php               → findSidecarSubs / sidecarToVtt (folder sidecars + MW_SUBS_DIR overlay)
web/view.php → video player (movi-player normally; avbridge-player for needs_fix=1); `<track>` from sidecars; manager upload form
web/vendor/avbridge/ → avbridge player-browser + libav WASM (lazy-loaded)
web/getcover.php       → ?id= video/still; ?sid= show poster (else cover_video_id); ?sid=&season= season then show poster; else folder/ffmpeg
web/getsub.php         → ?id=&n= sidecar as UTF-8 WebVTT (discover next to video + overlay; CP1251 / MicroDVD)
web/uploadsub.php      → manager/admin POST overlay .srt/.vtt/.sub
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
- Overlay subs dir `subs/` (`MW_SUBS_DIR`) must be writable (0777) by `www` — not under `web/`
- PHP `session.save_path` must be writable by `www` (FreeBSD default `/tmp` is usually OK)
- Until `php users.php add …` has created a user, off-net `/mweb/` only shows the login page. `MW_ALLOW_NETS` (LAN/VPN) can watch as Guest without an account
- **ffmpeg not on www user's PATH!** → Use `/usr/local/bin/ffmpeg` explicitly (`MW_FFMPEG` constant)
- Many video directories already contain `cover.jpg` / `thumb.jpg` files → `getcover.php` uses TMDB path when set (`videos.poster_path` / season / show), else these sidecars, else ffmpeg (video `?id=` only)
- Sidecar subs (`.srt` / `.sub` / `.vtt`) are discovered at play time (`subs.php`) from the video folder plus `MW_SUBS_DIR/{id}/`; `getsub.php` converts to UTF-8 VTT (Windows-1251, MicroDVD). Do not hotlink `/notor/….srt`. No TMDB/LLM on this path. `/notor/` and `/act_tor/` stay public; the PHP UI is session-gated except `MW_ALLOW_NETS` (viewer Guest).
- **Never suggest server remux/re-encode to H.264** to fix browser playback / `needs_fix` — not feasible; use avbridge + Firefox swscale→RGBA in `player-browser.js`

## FreeBSD notes

- `sed -i` requires an empty backup suffix argument: `sed -i '' 's/old/new/g' file`
- This is different from GNU sed on Linux; the empty string means "don't create a backup file"

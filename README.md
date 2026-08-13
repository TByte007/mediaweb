# MediaWeb

PHP tool that scans video directories, extracts metadata with MediaInfo, and stores it in SQLite. A web interface under `web/` lets you browse the library with search and genre filters, open series by show → season → episode, view thumbnails and play counts, and play videos in the browser (movi-player normally; avbridge for files marked `needs_fix`).

## Features

- Incremental directory scans (new files only by default)
- MediaInfo → SQLite catalog (`media.db`)
- **Move adopt** — same file under a new path (e.g. between media roots) reuses the DB row (plays / names / ids); soft-delete when files disappear
- Title enrich after each scan: folder/filename → (optional LLM search terms) → TMDB → `name` / `series.title`, genres, TV type, score, poster; LLM/PHP gap-fill
- Web library with search, genre filter (`?genre=`), thumbnails (TMDB poster when known), and play counts
- **Series mode** (`?mode=series`): browse show → season → episodes (detected at scan time); series cards show TMDB TV type
- Browser playback via movi-player; **avbridge** (libav WASM) for files flagged `needs_fix`; player shows TMDB score when enrich has filled it
- Session login (viewer / manager / admin). Managers can upload sidecar subtitles into a MediaWeb overlay dir (not the media roots). CIDRs in `MW_ALLOW_NETS` browse as Guest (viewer) without an account.

## Requirements

- PHP (CLI + Apache/`mod_php` or equivalent) with SQLite3
- [MediaInfo](https://mediaarea.net/en/MediaInfo) CLI
- `ffmpeg` / `ffprobe` (set absolute paths in config — may not be on the web user’s `PATH`)
- HTTP byte-range support on video URL prefixes (`Accept-Ranges: bytes`)

## Quick start

```bash
cp config.example.php config.php
# Edit MW_FFMPEG, MW_FFPROBE, MW_BASE_URL, MW_MEDIA_DIRS

php scan.php --verbose
php list.php --limit=20
php users.php add you --role=admin
```

Point Apache (or similar) at `web/` under your chosen `MW_BASE_URL`. Map each dir’s `url` prefix to its `fs` tree so the player can fetch media.

Ensure `web/covers/` and `subs/` (`MW_SUBS_DIR`) are writable by the web user. Off-net visitors need `php users.php add`. LAN/VPN CIDRs in `MW_ALLOW_NETS` can watch as Guest without an account.

## CLI

```bash
php scan.php --help
php scan.php --verbose                 # incremental scan + link + enrich titles
php scan.php --force-rescan            # re-extract metadata for known files
php scan.php --scan-only --verbose     # refresh needs_fix (PTS probe)
php scan.php --titles-backfill         # re-link + enrich (no tree walk)
php scan.php --titles-backfill --force # full display-name rebuild
php scan.php --no-tmdb --no-llm        # enrich without remote layers (tests)

php list.php --format=HEVC
php list.php --name="search" --limit=20
php list.php --count --format=AVC
php list.php --columns=filename,width,height,duration_secs,needs_fix

php users.php add NAME --role=admin|manager|viewer
php users.php passwd NAME
php users.php role NAME ROLE
php users.php list
php users.php del NAME
```

**Default scan:** skip files already in the DB; probe new ones; adopt moved files (same size+name, else unique size when the old path is gone); mark missing rows `is_deleted=1`. Then `linkSeries()` + `enrichTitles()`. Cron: `php scan.php` is enough when keys are set — it also fills score/poster for rows that already have `tmdb_id` and re-polls them on a weekly-ish schedule. Use `--titles-backfill --force` only for a full display-name rebuild from cached TMDB ids.

## Config

Shared constants live in `config.php` (gitignored). Start from `config.example.php`:

| Constant | Role |
|----------|------|
| `MW_DB` | SQLite path (default `./media.db`) |
| `MW_BASE_URL` | Web app URL prefix (e.g. `/mweb/`) |
| `MW_SUBS_DIR` | Overlay subtitle uploads (`./subs/{video_id}/`, not under `web/`) |
| `MW_ALLOW_NETS` | CIDRs that skip login as viewer (empty = login wall) |
| `MW_FFMPEG` / `MW_FFPROBE` | Absolute binary paths |
| `MW_MEDIA_DIRS` | Media roots: `[ 'fs' => path, 'url' => apache_prefix ]` |
| `MW_TMDB_TOKEN` | Optional TMDB Read Access Token for enrich (empty disables) |
| `MW_TMDB_MIN_SECS` | Min `duration_secs` for TMDB video queries (default 600 = 10m) |
| `MW_TMDB_REFRESH_DAYS` | Re-poll score/poster after this many days (default 7) |
| `MW_TMDB_REFRESH_JITTER` | ± days hash jitter on refresh interval (default 3) |
| `MW_LLM_URL` | Optional llama-server base URL for enrich (empty disables) |
| `MW_LLM_MODEL` | Model id (required on multi-model routers) |
| `MW_LLM_TIMEOUT` | Chat request timeout seconds (default 60) |
| `MW_LLM_PARALLEL` | Concurrent LLM chat requests (1=serial; dense~4; MoE~8) |

Each storage entry is independent: `[ 'fs' => '/path/on/disk', 'url' => '/apache_prefix/' ]`. Only the URL prefixes need to be public.

Display titles: MediaInfo stays in `videos.title`; enrich discovers TMDB ids from folder/filename (LLM proposes search terms), then writes `videos.name` / `series.title` plus `genre_ids`, `vote_average`, `poster_path`, `overview`, and `tmdb_refreshed_at` (and series `tmdb_type`) from TMDB. Episodes store TMDB stills on `videos.poster_path`; season posters and season overviews live in `series_seasons`; show overview on `series.overview`. Rows without `tmdb_id` are not re-queried for score/poster/overview. Known ids with a null stamp (or past the jittered interval) refresh on ordinary `php scan.php`. UI prefers `name`; Library/Series filter with `?genre=`; player shows score + plot; series seasons page and episode list show show/season overviews; `getcover.php?id=` / `?sid=` / `?sid=&season=` (CDN cache, then folder/ffmpeg for video ids). Never call the TMDB API or the LLM from page views. Test overrides: `--no-tmdb` / `--no-llm`. `--force` rebuilds display names from cached ids — not required for score/poster/overview backfill.

## Browser playback and `needs_fix`

Some older AVI / MPEG-4 Part 2 (Xvid/DivX) files play fine in VLC but jitter or fail in a browser demuxer. MediaWeb marks those after a **PTS probe** (`needs_fix=1`) — codec alone is not enough. The library shows **[!]**; the player uses **avbridge** (or `?player=avbridge`). Vendored under `web/vendor/avbridge/`.

Bulk server-side remux/re-encode to H.264 is intentionally out of scope.

## Layout

```
scan.php / list.php     CLI
config.php              local config (from config.example.php)
media.db                SQLite catalog
web/                    public app
```

See `AGENTS.md` for scanner details, `needs_fix` rules, and avbridge notes.

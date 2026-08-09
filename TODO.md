# MediaWeb TODO

## Titles / metadata

- [x] **Fix code-only gate** — one-word episode titles (`Progress`, `Babel`, `Duet`, …) must not be treated as release groups. When `episode_title` is already set, prefer PHP `{show}: {title} {SxxExx}` (no LLM).
- [x] **TMDB enrichment** — in unified `enrichTitles()` after `linkSeries()`; `series.tmdb_id` / `videos.tmdb_id`; duration gate `MW_TMDB_MIN_SECS` (default 600); skip ATGM.
- [x] **Unified title pipeline** — dirs/files → LLM search terms → TMDB → id; LLM/PHP gap-fill; `linkSeries` preserves title when `tmdb_id` set; `--force` refreshes from cached ids.
- [x] **Double-episode filenames** — strip `&e02` / `-e02` from the title rest (keep first episode only); keep `Part 1 & 2` in `cleanEpisodeTitle`. No `episode_end` column.
- [ ] **Reject truncated LLM episode names** — dangling tails (`Return to`, `Time to`, `One Little`) should fail validation and fall back (filename / PHP), not get saved.
- [ ] **Admin / manual override** — small UI (or CLI) to edit `videos.name` / `series.title` without `--force` over the whole library.

## Series / library

- [ ] **ATGM (and similar dump “shows”)** — keep as series for browse if useful, but consider a flag or UI hint so they don’t get the same title expectations as scripted TV; skip TMDB for those roots.
- [ ] **Series cover picks** — prefer a mid-season episode or existing `cover.jpg` over whatever `linkSeries` first finds.

## Ops / polish

- [x] **Cron** — incremental `php scan.php` already enriches title gaps when TMDB/LLM are configured; `--series-backfill --force` refreshes from cached TMDB ids; `--no-tmdb` / `--no-llm` for tests.
- [ ] **Soft-deleted purge** — optional CLI to `DELETE FROM videos WHERE is_deleted=1` (and orphan covers), with a dry-run.

## Out of scope (do not do)

- Server remux / re-encode to H.264 for `needs_fix` — avbridge only.
- Live LLM or TMDB calls from Apache page views.

# MediaWeb TODO

## Titles / metadata

- [x] **Fix code-only gate** — one-word episode titles (`Progress`, `Babel`, `Duet`, …) must not be treated as release groups. When `episode_title` is already set, prefer PHP `{show}: {title} {SxxExx}` (no LLM). Clear affected `videos.name` and re-run `--llm-titles` (or the PHP path).
- [ ] **TMDB enrichment** (optional CLI, not live in Apache) — resolve show `tmdb_id`, then S/E → official episode title; movies by search. Prefer TMDB over IMDb/MCP. **Only query when `duration_secs` &gt; ~10–20 min** (skip short clips / ATGM-length files). Cache IDs on `series` / videos; fill or correct `videos.name` / years.
- [x] **Double-episode filenames** — strip `&e02` / `-e02` from the title rest (keep first episode only); keep `Part 1 & 2` in `cleanEpisodeTitle`. No `episode_end` column.
- [ ] **Reject truncated LLM episode names** — dangling tails (`Return to`, `Time to`, `One Little`) should fail validation and fall back (filename / PHP), not get saved.
- [ ] **Admin / manual override** — small UI (or CLI) to edit `videos.name` / `series.title` without `--force` over the whole library.

## Series / library

- [ ] **ATGM (and similar dump “shows”)** — keep as series for browse if useful, but consider a flag or UI hint so they don’t get the same title expectations as scripted TV; skip TMDB for those roots.
- [ ] **Series cover picks** — prefer a mid-season episode or existing `cover.jpg` over whatever `linkSeries` first finds.

## Ops / polish

- [ ] **Document cron split** — incremental `php scan.php` vs optional `--llm-titles` / future `--tmdb-titles` when the GPU box is up.
- [ ] **Soft-deleted purge** — optional CLI to `DELETE FROM videos WHERE is_deleted=1` (and orphan covers), with a dry-run.

## Out of scope (do not do)

- Server remux / re-encode to H.264 for `needs_fix` — avbridge only.
- Live LLM or TMDB calls from Apache page views.

<?php require_once __DIR__ . '/helpers.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MediaWeb - <?= !empty($search) ? htmlspecialchars($search) : 'Video Library' ?></title>
<style>
:root {
    --bg: #0b0d14;
    --bg2: #131625;
    --card: #10131f;
    --accent: #5e72e4;
    --muted: #8795b0;
    --text: #e4e9f2;
    --r: 10px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", Roboto, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
}
.topbar {
    position: sticky; top: 0; z-index: 10;
    background: var(--bg2);
    border-bottom: 1px solid rgba(255,255,255,0.04);
    padding: 16px 0;
}
.wrap { max-width: 1400px; margin: 0 auto; padding: 0 24px; }
.header-row { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
.logo {
    display: flex; flex-direction: column; gap: 1px;
    font-size: 19px; font-weight: 700; color: var(--accent);
    text-decoration: none; white-space: nowrap; line-height: 1.05;
}
.logo span { color: var(--text); }
.logo .logo-owner {
    font-size: 10px; font-weight: 600; letter-spacing: 0.14em;
    text-transform: uppercase; color: var(--muted);
}
.search-wrap { flex: 1 1 200px; max-width: 420px; position: relative; }
.search-input {
    width: 100%; padding: 9px 36px 9px 36px;
    border-radius: 999px; border: 1px solid rgba(255,255,255,0.06);
    background: rgba(255,255,255,0.03); color: var(--text);
    font-size: 14px; outline: none; transition: border 0.2s, box-shadow 0.2s;
}
.search-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(94,114,228,0.18); }
.search-input::-webkit-search-cancel-button { -webkit-appearance: none; appearance: none; }
.search-icon {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    color: var(--muted); pointer-events: none;
}
.search-clear {
    position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
    width: 26px; height: 26px; border: 0; border-radius: 50%;
    background: transparent; color: var(--muted); cursor: pointer;
    font-size: 18px; line-height: 1; display: none;
}
.search-clear:hover { color: var(--text); background: rgba(255,255,255,0.06); }
.search-wrap.has-query .search-clear { display: block; }
.player-pref {
    flex: 0 0 auto; padding: 8px 12px; border-radius: 999px;
    border: 1px solid rgba(255,255,255,0.06); background: rgba(255,255,255,0.03);
    color: var(--text); font-size: 13px; outline: none; cursor: pointer;
}
.player-pref:focus { border-color: var(--accent); }
.player-pref option { color: #1a1a1a; background: #fff; }
.stats { font-size: 12px; color: var(--muted); white-space: nowrap; }
.len-filters {
    display: inline-flex; gap: 2px; padding: 3px;
    border-radius: 999px; border: 1px solid rgba(255,255,255,0.06);
    background: rgba(255,255,255,0.03);
}
.len-btn {
    padding: 6px 12px; border-radius: 999px; text-decoration: none;
    font-size: 12px; font-weight: 600; color: var(--muted);
    transition: color 0.15s, background 0.15s;
}
.len-btn:hover { color: var(--text); background: rgba(255,255,255,0.05); }
.len-btn.active { color: #fff; background: var(--accent); }
.len-btn .len-hint { margin-left: 4px; font-size: 10px; font-weight: 500; opacity: 0.55; }
main { max-width: 1400px; margin: 0 auto; padding: 24px 24px 60px; }
.info { margin-bottom: 14px; font-size: 12px; color: var(--muted); }
.info strong { color: var(--accent); }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 16px; }
.card {
    background: var(--card); border-radius: var(--r); overflow: hidden;
    border: 1px solid rgba(255,255,255,0.04);
    transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
    cursor: pointer;
}
.card:hover { transform: translateY(-2px); box-shadow: 0 10px 36px rgba(0,0,0,0.5); border-color: rgba(94,114,228,0.3); }
.thumb {
    position: relative; width: 100%; padding-top: 56.25%; background: #06080e;
}
.thumb img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
.tags { position: absolute; bottom: 6px; right: 6px; display: flex; gap: 4px; }
.tag {
    padding: 2px 6px; border-radius: 4px;
    background: rgba(6,8,14,0.85); font-size: 9px; font-weight: 600; color: #b0bccc;
}
.tag.time { color: #7de9be; }
.play-badge {
    position: absolute; top: 6px; left: 6px;
    padding: 2px 7px; border-radius: 4px;
    background: rgba(94,114,228,0.92);
    font-size: 9px; font-weight: 700; color: #fff;
    display: flex; align-items: center; gap: 3px;
}
.card-body { padding: 9px 11px 10px; display: flex; flex-direction: column; gap: 3px; }
.card-title {
    font-size: 12px; font-weight: 600; color: var(--text);
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.card-meta { font-size: 10px; color: var(--muted); }
.empty { text-align: center; padding: 60px 16px; color: var(--muted); }
/* Video page */
.view-grid {
    display: flex; gap: 32px; margin-top: 0;
}
@media(min-width:900px) { main { display: flex; flex-direction: column; } }
.player-wrap {
    flex: 1; background: #000; border-radius: 12px; overflow: hidden;
    position: relative; width: 100%; aspect-ratio: 16 / 9; max-height: 85vh; min-height: 240px;
}
.player-wrap > avbridge-player,
.player-wrap > avbridge-video,
.player-wrap > movi-player {
    position: absolute; inset: 0; width: 100%; height: 100%; display: block;
}
avbridge-video::part(stage),
avbridge-player::part(video) { position: absolute; inset: 0; width: 100%; height: 100%; }
.player-wrap video { width: 100%; height: 100%; }
.info-panel {
    margin-top: 16px; background: var(--card); border-radius: 12px;
    padding: 20px 22px 18px; border: 1px solid rgba(255,255,255,0.06);
    display: flex; flex-direction: column; gap: 14px;
}
.info-panel h1, .video-title {
    font-size: 22px; font-weight: 700; line-height: 1.3; letter-spacing: -0.01em;
    display: flex; flex-direction: column; gap: 4px;
}
.video-title-sub {
    font-size: 13px; font-weight: 500; letter-spacing: 0; color: var(--muted);
}
.meta-grid {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;
}
.meta {
    border-radius: 8px; padding: 10px 12px 10px 14px;
    border: 1px solid rgba(255,255,255,0.05);
    border-left-width: 3px;
    display: flex; flex-direction: column; gap: 4px; min-width: 0;
}
.meta-k {
    font-size: 10px; font-weight: 700; letter-spacing: 0.08em;
    text-transform: uppercase;
}
.meta-v {
    font-size: 15px; font-weight: 600; color: var(--text);
    font-variant-numeric: tabular-nums; line-height: 1.2;
    overflow-wrap: anywhere;
}
.meta-v .meta-note {
    display: block; margin-top: 2px;
    font-size: 11px; font-weight: 500; color: var(--muted);
}
.meta-codec { background: rgba(124,156,255,0.1); border-left-color: #7c9cff; }
.meta-codec .meta-k { color: #9bb3ff; }
.meta-res { background: rgba(94,234,212,0.09); border-left-color: #5eead4; }
.meta-res .meta-k { color: #7ef0dc; }
.meta-dur { background: rgba(134,239,172,0.09); border-left-color: #86efac; }
.meta-dur .meta-k { color: #9df4bc; }
.meta-size { background: rgba(251,191,36,0.1); border-left-color: #fbbf24; }
.meta-size .meta-k { color: #fcd34d; }
.meta-audio { background: rgba(167,139,250,0.1); border-left-color: #a78bfa; }
.meta-audio .meta-k { color: #c4b5fd; }
.meta-subs { background: rgba(249,168,212,0.09); border-left-color: #f9a8d4; }
.meta-subs .meta-k { color: #fbcfe8; }
.meta-plays { background: rgba(56,189,248,0.1); border-left-color: #38bdf8; }
.meta-plays .meta-k { color: #7dd3fc; }
.meta-player { background: rgba(253,186,116,0.1); border-left-color: #fdba74; }
.meta-player .meta-k { color: #fdba74; }
.play-note {
    background: rgba(230,168,23,0.1); border: 1px solid rgba(230,168,23,0.28);
    border-left: 3px solid #e6a817; border-radius: 8px; padding: 12px 14px;
    font-size: 13px; line-height: 1.5; color: #e4d4b4;
}
.play-note-title {
    font-size: 10px; font-weight: 700; letter-spacing: 0.08em;
    text-transform: uppercase; color: #f0c14d; margin-bottom: 6px;
}
.play-note p + p { margin-top: 6px; color: #c9b896; }
.play-note strong { color: #f5e6c8; font-weight: 600; }
.play-note a {
    color: #f0c14d; font-weight: 600; text-decoration: underline;
    text-underline-offset: 2px;
}
.play-note a:hover { color: #ffe08a; }
.view-nav {
    display: flex; flex-wrap: wrap; gap: 10px; align-items: stretch;
}
.view-nav-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    padding: 12px 18px; border-radius: 10px;
    border: 1px solid rgba(94,114,228,0.45);
    background: rgba(94,114,228,0.18);
    color: #e4e9f2; text-decoration: none;
    font-size: 15px; font-weight: 600; line-height: 1.25;
}
.view-nav-btn:hover {
    background: var(--accent); border-color: var(--accent); color: #fff;
}
.view-nav-btn.is-disabled,
.view-nav-btn.is-disabled:hover {
    opacity: 0.4; cursor: not-allowed;
    background: rgba(255,255,255,0.04); border-color: rgba(255,255,255,0.08);
    color: var(--muted); pointer-events: none;
}
.back-link {
    display: inline-flex; align-items: center; gap: 5px;
    color: var(--muted); text-decoration: none; font-size: 13px; font-weight: 500;
}
.back-link:hover { color: var(--accent); }
@media(max-width:800px) {
    .view-grid { flex-direction: column; }
    .meta-grid { grid-template-columns: repeat(2, 1fr); }
    .grid { grid-template-columns: repeat(auto-fill, minmax(155px, 1fr)); gap: 10px; }
}
</style>
</head>
<body>
<header class="topbar">
<div class="wrap">
    <div class="header-row">
        <a class="logo" href="<?= $basePath ?>"><?php if (MW_OWNER !== ''): ?><span class="logo-owner"><?= htmlspecialchars(MW_OWNER) ?></span><?php endif; ?>Media<span>Web</span></a>
        <?php
        $browseMode = (($_GET['mode'] ?? '') === 'series' || ($mode ?? '') === 'series') ? 'series' : 'library';
        $searchPlaceholder = $browseMode === 'series' ? 'Search series...' : 'Search videos...';
        ?>
        <form class="search-wrap<?= ($search ?? '') !== '' ? ' has-query' : '' ?>" action="<?= $basePath ?>" method="get" id="search-form">
            <span class="search-icon">&#128269;</span>
            <input class="search-input" id="search-input" type="search" name="q" placeholder="<?= htmlspecialchars($searchPlaceholder) ?>" value="<?= htmlspecialchars($search ?? '') ?>" autocomplete="off">
            <button type="button" class="search-clear" id="search-clear" aria-label="Clear search">&times;</button>
            <?php if ($browseMode === 'series'): ?>
            <input type="hidden" name="mode" value="series">
            <?php if (!empty($sid)): ?><input type="hidden" name="sid" value="<?= (int)$sid ?>"><?php endif; ?>
            <?php if (isset($season) && $season !== null): ?><input type="hidden" name="season" value="<?= (int)$season ?>"><?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($len) && $browseMode === 'library'): ?>
            <input type="hidden" name="len" value="<?= htmlspecialchars($len) ?>">
            <?php endif; ?>
        </form>
        <nav class="len-filters" aria-label="Browse mode">
            <a class="len-btn<?= $browseMode === 'library' ? ' active' : '' ?>" href="<?= htmlspecialchars($basePath . (!empty($len) ? '?len=' . urlencode($len) : '')) ?>">Library</a>
            <a class="len-btn<?= $browseMode === 'series' ? ' active' : '' ?>" href="<?= htmlspecialchars($basePath . '?mode=series') ?>">Series</a>
        </nav>
        <?php if ($browseMode === 'library' && isset($lenFilters)): ?>
        <nav class="len-filters" aria-label="Filter by length">
            <a class="len-btn<?= $len === '' ? ' active' : '' ?>" href="<?= htmlspecialchars(pageUrl(['len' => null, 'page' => null, 'mode' => null])) ?>">All</a>
            <?php foreach ($lenFilters as $key => [, $label, $hint]): ?>
            <a class="len-btn<?= $len === $key ? ' active' : '' ?>" href="<?= htmlspecialchars(pageUrl(['len' => $key, 'page' => null, 'mode' => null])) ?>"><?= $label ?> <span class="len-hint"><?= htmlspecialchars($hint) ?></span></a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
        <select class="player-pref" id="player-pref" title="Player override" aria-label="Player">
            <option value="auto">Player: auto</option>
            <option value="movi">Player: movi</option>
            <option value="avbridge">Player: avbridge</option>
        </select>
        <div class="stats"><?php
            if (isset($total) && $total) {
                if ($browseMode === 'series' && ($seriesLevel ?? '') === 'shows') echo number_format($total) . ' series';
                elseif ($browseMode === 'series' && ($seriesLevel ?? '') === 'seasons') echo number_format($total) . ' seasons';
                elseif ($browseMode === 'series') echo number_format($total) . ' episodes';
                else echo number_format($total) . ' videos';
            }
        ?></div>
    </div>
</div>
</header>
<script>
(function () {
    const KEY = 'mw_player';
    const base = <?= json_encode($basePath ?? '/') ?>;
    const sel = document.getElementById('player-pref');
    const form = document.getElementById('search-form');
    const input = document.getElementById('search-input');
    const clear = document.getElementById('search-clear');
    if (sel) {
        const cur = sessionStorage.getItem(KEY) || 'auto';
        if ([...sel.options].some((o) => o.value === cur)) sel.value = cur;
        sel.addEventListener('change', () => {
            sessionStorage.setItem(KEY, sel.value);
            const params = new URLSearchParams(location.search);
            if (!params.has('view')) return;
            if (sel.value === 'auto') params.delete('player');
            else params.set('player', sel.value);
            location.search = params.toString();
        });
    }
    function syncClear() {
        if (!form || !input) return;
        form.classList.toggle('has-query', input.value.length > 0);
    }
    if (input && clear) {
        input.addEventListener('input', syncClear);
        clear.addEventListener('click', () => {
            input.value = '';
            syncClear();
            const params = new URLSearchParams(location.search);
            if (!params.has('q')) { input.focus(); return; }
            params.delete('q');
            params.delete('page');
            const qs = params.toString();
            location.href = qs ? base + '?' + qs : base;
        });
    }
    document.addEventListener('click', (e) => {
        const nav = e.target.closest('.card[data-href]');
        if (nav) {
            location.href = nav.dataset.href;
            return;
        }
        const card = e.target.closest('.card[data-id]');
        if (!card) return;
        const p = sessionStorage.getItem(KEY) || 'auto';
        let url = base + '?view=' + card.dataset.id;
        if (p !== 'auto') url += '&player=' + encodeURIComponent(p);
        location.href = url;
    });

    // Grid markup comes after this script.
    document.addEventListener('DOMContentLoaded', () => {
        const grid = document.querySelector('.grid[data-pages]');
        const more = document.querySelector('.grid-more');
        const spacerTop = document.querySelector('.grid-spacer-top');
        if (!grid || !more || !spacerTop) return;

        const pages = parseInt(grid.dataset.pages, 10) || 1;
        if (pages <= 1) return;

        const maxPages = Math.max(1, parseInt(grid.dataset.window, 10) || 2) * 2 + 1;
        let firstPage = parseInt(grid.dataset.page, 10) || 1;
        let lastPage = firstPage;
        let topH = 0;
        let loading = false;
        const pageH = Object.create(null);

        function pageCards(p) {
            return grid.querySelectorAll('.card[data-page="' + p + '"]');
        }
        function measurePage(p) {
            const cards = pageCards(p);
            if (!cards.length) return 0;
            const start = cards[0].offsetTop;
            const next = grid.querySelector('.card[data-page="' + (p + 1) + '"]');
            if (next) return next.offsetTop - start;
            const last = cards[cards.length - 1];
            const gap = parseFloat(getComputedStyle(grid).rowGap) || 0;
            return last.offsetTop + last.offsetHeight - start + gap;
        }
        function setTop(h) {
            topH = Math.max(0, h);
            spacerTop.style.height = topH + 'px';
        }
        function syncMore() {
            more.hidden = lastPage >= pages;
        }
        function unloadTop() {
            while (lastPage - firstPage + 1 > maxPages && firstPage < lastPage) {
                const cards = pageCards(firstPage);
                if (!cards.length) break;
                const h = measurePage(firstPage);
                pageH[firstPage] = h;
                cards.forEach((c) => c.remove());
                setTop(topH + h);
                firstPage++;
            }
        }
        function unloadBot() {
            while (lastPage - firstPage + 1 > maxPages && lastPage > firstPage) {
                const cards = pageCards(lastPage);
                if (!cards.length) break;
                if (cards[0].getBoundingClientRect().top < innerHeight + 400) break;
                cards.forEach((c) => c.remove());
                lastPage--;
            }
        }
        async function fetchPage(p) {
            const params = new URLSearchParams(location.search);
            params.set('page', String(p));
            params.set('partial', '1');
            const res = await fetch(base + '?' + params.toString());
            return (await res.text()).trim();
        }
        function near(el) {
            if (el.hidden) return false;
            const r = el.getBoundingClientRect();
            return r.top < innerHeight + 300 && r.bottom > -300;
        }
        function wantNext() {
            return lastPage < pages && near(more);
        }
        function wantPrev() {
            if (firstPage <= 1 && topH <= 0) return false;
            if (topH > 0) {
                const edge = spacerTop.getBoundingClientRect().bottom;
                return edge > 0 && edge < innerHeight + 300;
            }
            return near(spacerTop);
        }
        function poke() {
            if (wantNext()) loadNext();
            else if (wantPrev()) loadPrev();
        }
        async function loadNext() {
            if (loading || !wantNext()) return;
            loading = true;
            try {
                const html = await fetchPage(lastPage + 1);
                if (!html) lastPage = pages;
                else {
                    grid.insertAdjacentHTML('beforeend', html);
                    lastPage++;
                    unloadTop();
                }
            } catch (_) {}
            syncMore();
            loading = false;
            requestAnimationFrame(poke);
        }
        async function loadPrev() {
            if (loading || !wantPrev()) return;
            loading = true;
            try {
                const prevPage = firstPage - 1;
                if (prevPage < 1) setTop(0);
                else {
                    const html = await fetchPage(prevPage);
                    if (!html) { setTop(0); firstPage = 1; }
                    else {
                        const y = window.scrollY;
                        grid.insertAdjacentHTML('afterbegin', html);
                        firstPage = prevPage;
                        const h = pageH[prevPage] != null ? pageH[prevPage] : measurePage(prevPage);
                        delete pageH[prevPage];
                        if (topH > 0) setTop(topH - h);
                        else window.scrollTo(0, y + h);
                        unloadBot();
                    }
                }
            } catch (_) {}
            syncMore();
            loading = false;
            requestAnimationFrame(poke);
        }

        syncMore();
        let tick = false;
        window.addEventListener('scroll', () => {
            if (tick) return;
            tick = true;
            requestAnimationFrame(() => {
                tick = false;
                if (topH > 0 && grid.getBoundingClientRect().top > innerHeight)
                    window.scrollTo(0, Math.max(0, spacerTop.offsetTop + topH - 120));
                poke();
            });
        }, { passive: true });
        new IntersectionObserver(() => poke(), { rootMargin: '300px 0px' }).observe(more);
        poke();
    });
})();
</script>

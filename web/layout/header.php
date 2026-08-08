<?php require __DIR__ . '/helpers.php'; ?>
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
    font-size: 19px; font-weight: 700; color: var(--accent);
    text-decoration: none; white-space: nowrap;
}
.logo span { color: var(--text); }
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
/* Pagination */
.pag {
    margin-top: 24px; display: flex; justify-content: center; align-items: center; gap: 4px;
}
.pag a, .pag span {
    display: flex; align-items: center; justify-content: center;
    min-width: 32px; height: 32px; border-radius: 6px; font-size: 12px;
    color: var(--muted); text-decoration: none; transition: background 0.15s, color 0.15s;
}
.pag a:hover { background: rgba(255,255,255,0.06); color: #fff; }
.pag a.active { background: var(--accent); color: #fff; }
.pag .dot { padding: 0 4px; }
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
    flex: 0 0 340px; background: var(--card); border-radius: 12px;
    padding: 20px; border: 1px solid rgba(255,255,255,0.06);
    display: flex; flex-direction: column; gap: 8px;
}
.info-panel h1 { font-size: 20px; font-weight: 700; line-height: 1.35; }
.info-table { width: 100%; font-size: 12px; margin-top: 8px; }
.info-table tr { border-bottom: 1px solid rgba(255,255,255,0.04); }
.info-table td:first-child { color: var(--muted); padding: 5px 8px 5px 0; }
.info-table td:last-child { text-align: right; padding: 5px 0; }
.back-link { margin-top: 6px; display: inline-flex; align-items: center; gap: 5px; color: var(--muted); text-decoration: none; font-size: 12px; }
.back-link:hover { color: var(--accent); }
@media(max-width:800px) {
    .view-grid { flex-direction: column; }
    .info-panel { flex: 1; }
    .grid { grid-template-columns: repeat(auto-fill, minmax(155px, 1fr)); gap: 10px; }
}
</style>
</head>
<body>
<header class="topbar">
<div class="wrap">
    <div class="header-row">
        <a class="logo" href="<?= $basePath ?>">Media<span>Web</span></a>
        <form class="search-wrap<?= ($search ?? '') !== '' ? ' has-query' : '' ?>" action="<?= $basePath ?>" method="get" id="search-form">
            <span class="search-icon">&#128269;</span>
            <input class="search-input" id="search-input" type="search" name="q" placeholder="Search videos..." value="<?= htmlspecialchars($search ?? '') ?>" autocomplete="off">
            <button type="button" class="search-clear" id="search-clear" aria-label="Clear search">&times;</button>
        </form>
        <select class="player-pref" id="player-pref" title="Player override" aria-label="Player">
            <option value="auto">Player: auto</option>
            <option value="movi">Player: movi</option>
            <option value="avbridge">Player: avbridge</option>
        </select>
        <div class="stats"><?= isset($total) && $total ? number_format($total) . ' videos' : '' ?></div>
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
            if (new URLSearchParams(location.search).has('q')) location.href = base;
            else input.focus();
        });
    }
    document.addEventListener('click', (e) => {
        const card = e.target.closest('.card[data-id]');
        if (!card) return;
        const p = sessionStorage.getItem(KEY) || 'auto';
        let url = base + '?view=' + card.dataset.id;
        if (p !== 'auto') url += '&player=' + encodeURIComponent(p);
        location.href = url;
    });
})();
</script>

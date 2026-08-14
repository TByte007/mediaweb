CREATE TABLE videos (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    filepath        TEXT NOT NULL UNIQUE,
    filename        TEXT NOT NULL,
    directory       TEXT NOT NULL,
    filesize_bytes  INTEGER NOT NULL,
    duration_secs   INTEGER,
    video_format    TEXT,
    video_codec     TEXT,
    width           INTEGER,
    height          INTEGER,
    aspect_ratio    TEXT,
    frame_rate      TEXT,
    video_bitrate   INTEGER,
    audio_tracks    INTEGER DEFAULT 0,
    subtitle_tracks INTEGER DEFAULT 0,
    title           TEXT,
    full_info       TEXT,
    playback_count  INTEGER DEFAULT 0,
    needs_fix       INTEGER DEFAULT 0,
    is_deleted      INTEGER DEFAULT 0,
    scanned_at      DATETIME NOT NULL DEFAULT (datetime('now')),
    updated_at      DATETIME NOT NULL DEFAULT (datetime('now'))
, series_id INTEGER, season INTEGER, episode INTEGER, episode_title TEXT, name TEXT, tmdb_id INTEGER, genre_ids TEXT, vote_average REAL, poster_path TEXT, tmdb_refreshed_at TEXT, overview TEXT);
CREATE TABLE sqlite_sequence(name,seq);
CREATE INDEX idx_videos_directory ON videos(directory);
CREATE INDEX idx_videos_width   ON videos(width);
CREATE INDEX idx_videos_height  ON videos(height);
CREATE INDEX idx_videos_playback_count ON videos(playback_count DESC);
CREATE TABLE series (id INTEGER PRIMARY KEY AUTOINCREMENT, root_key TEXT NOT NULL UNIQUE, title TEXT NOT NULL, cover_video_id INTEGER, updated_at DATETIME NOT NULL DEFAULT (datetime('now')), tmdb_id INTEGER, genre_ids TEXT, tmdb_type TEXT, vote_average REAL, poster_path TEXT, tmdb_refreshed_at TEXT, overview TEXT);
CREATE INDEX idx_videos_series ON videos(series_id, season, episode);
CREATE INDEX idx_series_root ON series(root_key);
CREATE TABLE genres (id INTEGER PRIMARY KEY, name TEXT NOT NULL);
CREATE TABLE series_seasons (
    series_id INTEGER NOT NULL,
    season INTEGER NOT NULL,
    poster_path TEXT, overview TEXT,
    PRIMARY KEY (series_id, season)
);
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    role          TEXT NOT NULL CHECK (role IN ('viewer', 'manager', 'admin')),
    created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

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
);
CREATE TABLE sqlite_sequence(name,seq);
CREATE INDEX idx_videos_directory ON videos(directory);
CREATE INDEX idx_videos_width   ON videos(width);
CREATE INDEX idx_videos_height  ON videos(height);
CREATE INDEX idx_videos_playback_count ON videos(playback_count DESC);

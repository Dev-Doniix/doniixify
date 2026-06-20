CREATE TABLE IF NOT EXISTS artists (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(500) NOT NULL,
    name_sort VARCHAR(500) NOT NULL,
    album_count INT UNSIGNED NOT NULL DEFAULT 0,
    song_count INT UNSIGNED NOT NULL DEFAULT 0,
    cover_id VARCHAR(64) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name_sort (name_sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS albums (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    artist_id INT UNSIGNED NOT NULL,
    name VARCHAR(500) NOT NULL,
    name_sort VARCHAR(500) NOT NULL,
    year SMALLINT UNSIGNED DEFAULT NULL,
    genre VARCHAR(255) DEFAULT NULL,
    song_count INT UNSIGNED NOT NULL DEFAULT 0,
    duration INT UNSIGNED NOT NULL DEFAULT 0,
    cover_id VARCHAR(64) DEFAULT NULL,
    play_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_artist (artist_id),
    INDEX idx_name_sort (name_sort),
    INDEX idx_year (year),
    INDEX idx_genre (genre),
    INDEX idx_created (created_at),
    CONSTRAINT fk_albums_artist FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS songs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    album_id INT UNSIGNED NOT NULL,
    artist_id INT UNSIGNED NOT NULL,
    title VARCHAR(500) NOT NULL,
    title_sort VARCHAR(500) NOT NULL,
    track_number SMALLINT UNSIGNED DEFAULT NULL,
    disc_number SMALLINT UNSIGNED DEFAULT NULL,
    duration INT UNSIGNED NOT NULL DEFAULT 0,
    bitrate INT UNSIGNED DEFAULT NULL,
    suffix VARCHAR(10) NOT NULL,
    content_type VARCHAR(50) NOT NULL,
    size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    path VARCHAR(1000) NOT NULL UNIQUE,
    play_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_played_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modified_at DATETIME DEFAULT NULL,
    INDEX idx_album (album_id),
    INDEX idx_artist (artist_id),
    INDEX idx_title_sort (title_sort),
    INDEX idx_path (path(255)),
    CONSTRAINT fk_songs_album FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
    CONSTRAINT fk_songs_artist FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS playlists (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    comment TEXT DEFAULT NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 0,
    song_count INT UNSIGNED NOT NULL DEFAULT 0,
    duration INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    CONSTRAINT fk_playlists_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS playlist_songs (
    playlist_id INT UNSIGNED NOT NULL,
    song_id INT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (playlist_id, position),
    INDEX idx_song (song_id),
    CONSTRAINT fk_pls_playlist FOREIGN KEY (playlist_id) REFERENCES playlists(id) ON DELETE CASCADE,
    CONSTRAINT fk_pls_song FOREIGN KEY (song_id) REFERENCES songs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stars (
    user_id INT UNSIGNED NOT NULL,
    item_type ENUM('song','album','artist') NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    starred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, item_type, item_id),
    CONSTRAINT fk_stars_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scans (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME DEFAULT NULL,
    status ENUM('running','done','error') NOT NULL DEFAULT 'running',
    files_scanned INT UNSIGNED NOT NULL DEFAULT 0,
    files_added INT UNSIGNED NOT NULL DEFAULT 0,
    files_removed INT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

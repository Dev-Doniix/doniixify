CREATE TABLE IF NOT EXISTS user_song_plays (
    user_id INT NOT NULL,
    song_id INT NOT NULL,
    play_count INT NOT NULL DEFAULT 1,
    last_played_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, song_id),
    INDEX idx_user (user_id),
    INDEX idx_user_last (user_id, last_played_at),
    INDEX idx_user_count (user_id, play_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

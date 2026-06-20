ALTER TABLE active_devices
    ADD COLUMN playing_since DATETIME NULL DEFAULT NULL AFTER is_playing,
    ADD INDEX idx_playing_since (playing_since);

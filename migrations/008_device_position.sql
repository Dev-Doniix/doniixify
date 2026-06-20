ALTER TABLE active_devices
    ADD COLUMN current_position FLOAT NOT NULL DEFAULT 0 AFTER current_song_id,
    ADD COLUMN position_updated_at DATETIME NULL AFTER current_position;

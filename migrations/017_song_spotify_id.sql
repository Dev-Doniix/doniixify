ALTER TABLE songs ADD COLUMN spotify_id VARCHAR(32) NULL AFTER downloaded_by;
ALTER TABLE songs ADD INDEX idx_spotify_id (spotify_id);

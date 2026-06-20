ALTER TABLE playlists
    ADD COLUMN import_cache_json LONGTEXT NULL AFTER comment,
    ADD COLUMN import_status VARCHAR(32) NULL AFTER import_cache_json,
    ADD COLUMN import_updated_at DATETIME NULL AFTER import_status;

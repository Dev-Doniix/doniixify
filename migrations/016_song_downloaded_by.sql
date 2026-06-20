ALTER TABLE songs ADD COLUMN downloaded_by INT NULL AFTER created_at;
ALTER TABLE songs ADD INDEX idx_downloaded_by (downloaded_by);

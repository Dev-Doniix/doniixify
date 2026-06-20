CREATE TABLE IF NOT EXISTS playback_commands (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  command VARCHAR(32) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  consumed_at DATETIME DEFAULT NULL,
  INDEX idx_user_pending (user_id, consumed_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

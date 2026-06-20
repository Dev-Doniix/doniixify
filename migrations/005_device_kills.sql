CREATE TABLE IF NOT EXISTS device_kills (
    user_id INT NOT NULL,
    device_id VARCHAR(64) NOT NULL,
    killed_until DATETIME NOT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (user_id, device_id),
    INDEX idx_killed_until (killed_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

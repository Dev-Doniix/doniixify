CREATE TABLE IF NOT EXISTS search_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    query VARCHAR(255) NOT NULL,
    query_type VARCHAR(16) NOT NULL DEFAULT 'title',
    last_searched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    hit_count INT NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_query (user_id, query, query_type),
    KEY idx_user_recent (user_id, last_searched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS search_cache_db (
    cache_key VARCHAR(64) NOT NULL,
    items_json LONGTEXT NOT NULL,
    is_empty TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (cache_key),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

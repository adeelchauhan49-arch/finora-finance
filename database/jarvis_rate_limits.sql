USE finance_ai;

CREATE TABLE IF NOT EXISTS jarvis_rate_limits (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    window_started_at DATETIME NOT NULL,
    request_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_jarvis_rate_limits_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

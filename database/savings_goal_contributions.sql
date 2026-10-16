USE finance_ai;

ALTER TABLE savings_goals
    ADD UNIQUE KEY uq_savings_goals_user_id (user_id, id);

CREATE TABLE savings_goal_contributions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    goal_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    contribution_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_goal_contributions_owner_date (user_id, goal_id, contribution_date, id),
    CONSTRAINT fk_goal_contributions_owner_goal
        FOREIGN KEY (user_id, goal_id) REFERENCES savings_goals (user_id, id) ON DELETE CASCADE
) ENGINE=InnoDB;

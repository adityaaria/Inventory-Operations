CREATE TABLE IF NOT EXISTS operation_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id INT UNSIGNED NOT NULL,
    request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    completed BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_operation_request_actor_key (actor_id, request_key),
    CONSTRAINT fk_operation_request_actor FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB;

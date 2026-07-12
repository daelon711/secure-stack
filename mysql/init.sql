-- Foresight - initial schema
-- Mounted into /docker-entrypoint-initdb.d/ on the mysql container.
-- Runs ONCE, only when the data volume is empty (first boot).

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ============================================================
-- users
-- Referenced by: login.php, register.php, profile.php, config.php
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(50)  NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    email           VARCHAR(100) NOT NULL,
    avatar          VARCHAR(255) DEFAULT NULL,
    is_enabled      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- notes  ("what I learned today" notes)
-- Referenced by: index.php
-- ============================================================
CREATE TABLE IF NOT EXISTS notes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    title       VARCHAR(255) NOT NULL,
    is_done     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_notes_user (user_id, is_done, created_at),
    CONSTRAINT fk_notes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- game_scores  (maze + sudoku)
-- Referenced by: maze.php, sudoku.php, index.php (leaderboard + personal best)
-- ============================================================
CREATE TABLE IF NOT EXISTS game_scores (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    game        ENUM('maze', 'sudoku') NOT NULL,
    score       INT UNSIGNED NOT NULL,
    time_sec    DECIMAL(8,2) NOT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- speeds up "your best score / fastest time" lookups per user+game
    KEY idx_scores_user_game (user_id, game),
    -- speeds up leaderboard ORDER BY score DESC per game
    KEY idx_scores_game_score (game, score DESC),

    CONSTRAINT fk_scores_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- messages  (chat - content stored raw/unescaped, intentional XSS)
-- Referenced by: chat.php, index.php
-- ============================================================
CREATE TABLE IF NOT EXISTS messages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    content     VARCHAR(500) NOT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_messages_created (created_at),
    CONSTRAINT fk_messages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- activity_log  (real app events: logins, registers, uploads, scores...)
-- Referenced by: config.php -> log_event()
-- ============================================================
CREATE TABLE IF NOT EXISTS activity_log (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type          VARCHAR(50)  NOT NULL,
    user_id             INT UNSIGNED DEFAULT NULL,   -- NULL for failed logins on unknown usernames
    username_attempted  VARCHAR(50)  NOT NULL DEFAULT '',
    ip_address          VARCHAR(45)  NOT NULL DEFAULT '',  -- 45 = room for IPv6
    user_agent          VARCHAR(255) NOT NULL DEFAULT '',
    details             TEXT,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_activity_type (event_type, created_at),
    KEY idx_activity_user (user_id, created_at),
    CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- trap_log  (honeypot hits: robots.txt lure paths + hidden form fields)
-- Referenced by: config.php -> log_honeypot(), trap.php
-- ============================================================
CREATE TABLE IF NOT EXISTS trap_log (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trap_name     VARCHAR(100) NOT NULL,
    layer         VARCHAR(50)  NOT NULL,
    source_ip     VARCHAR(45)  NOT NULL DEFAULT '',
    method        VARCHAR(10)  NOT NULL DEFAULT '',
    request_uri   VARCHAR(500) NOT NULL DEFAULT '',
    user_agent    VARCHAR(255) NOT NULL DEFAULT '',
    referer       VARCHAR(500) NOT NULL DEFAULT '',
    details       TEXT,   -- json_encode()'d: post data, cookies, headers, detected tool
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_trap_name (trap_name, created_at),
    KEY idx_trap_ip (source_ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dominus Mini Consulta SQL 2.0
-- MySQL 5.7+ / MariaDB 10.3+, banco previamente criado no cPanel.
-- Importação opcional: o instalador PHP também cria estas tabelas.
-- Sem DROP, sem credenciais ou senha admin padrão.
CREATE TABLE IF NOT EXISTS dc_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    schema_version VARCHAR(20) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dc_machines (
    id CHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    category VARCHAR(50) NOT NULL,
    api_url VARCHAR(512) NOT NULL,
    api_username VARCHAR(128) NOT NULL,
    api_password_encrypted TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_dc_category (category),
    KEY idx_dc_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dc_login_guard (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    window_started BIGINT UNSIGNED NOT NULL DEFAULT 0,
    attempts INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RustDesk Panel — MySQL schema (reference; DB already provisioned)
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(128) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS operators (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(128) NULL,
  email VARCHAR(190) NULL,
  is_admin TINYINT NOT NULL DEFAULT 0,
  status TINYINT NOT NULL DEFAULT 1,
  note VARCHAR(300) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS operator_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  operator_id INT NOT NULL,
  token CHAR(64) NOT NULL UNIQUE,
  device_id VARCHAR(100) NULL,
  device_uuid VARCHAR(190) NULL,
  device_os VARCHAR(64) NULL,
  device_name VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen DATETIME NULL,
  revoked TINYINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_token_operator FOREIGN KEY (operator_id) REFERENCES operators(id) ON DELETE CASCADE,
  INDEX idx_token_operator (operator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS devices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  peer_id VARCHAR(100) NOT NULL UNIQUE,
  uuid VARCHAR(190) NULL, hostname VARCHAR(190) NULL, username VARCHAR(190) NULL,
  os VARCHAR(190) NULL, cpu VARCHAR(190) NULL, memory VARCHAR(64) NULL,
  version VARCHAR(64) NULL, last_ip VARCHAR(64) NULL, sysinfo_ver VARCHAR(64) NULL,
  -- Senha de uso unico exibida na tela do dispositivo, reportada no heartbeat
  -- para o operador nao precisar pedi-la por telefone. Guardada em texto claro
  -- porque precisa ser exibida; rotaciona a cada reinicio do cliente.
  conn_password VARCHAR(190) NULL, conn_password_at DATETIME NULL,
  first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_seen DATETIME NULL,
  online TINYINT NOT NULL DEFAULT 0,
  INDEX idx_device_online (online), INDEX idx_device_lastseen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migracao para bancos criados antes desta coluna existir (rode uma vez;
-- se ja existir, o MySQL acusa erro e pode ser ignorado):
--   ALTER TABLE devices ADD COLUMN conn_password VARCHAR(190) NULL,
--                       ADD COLUMN conn_password_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS connections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  guid CHAR(36) NULL UNIQUE, conn_id BIGINT NULL, session_id VARCHAR(64) NULL,
  device_id VARCHAR(100) NULL, device_uuid VARCHAR(190) NULL,
  peer_id VARCHAR(100) NULL, peer_name VARCHAR(190) NULL,
  ip VARCHAR(64) NULL, conn_type INT NULL, note VARCHAR(500) NULL,
  started_at DATETIME NULL, ended_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_conn_device (device_id), INDEX idx_conn_peer (peer_id),
  INDEX idx_conn_started (started_at), INDEX idx_conn_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_audit (
  id INT AUTO_INCREMENT PRIMARY KEY,
  operator_id INT NULL, username VARCHAR(64) NULL, success TINYINT NOT NULL DEFAULT 0,
  ip VARCHAR(64) NULL, device_id VARCHAR(100) NULL, user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_loginaudit_created (created_at), INDEX idx_loginaudit_op (operator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(64) PRIMARY KEY, v VARCHAR(255) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

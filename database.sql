SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = 'utf8mb4_unicode_ci';

CREATE TABLE IF NOT EXISTS organizations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('owner','admin','operator') NOT NULL DEFAULT 'owner',
  status ENUM('active','blocked') NOT NULL DEFAULT 'active',
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_admin_org FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(180) NOT NULL,
  slug VARCHAR(190) NOT NULL UNIQUE,
  description TEXT NULL,
  location_name VARCHAR(180) NULL,
  wifi_ssid VARCHAR(180) NULL,
  expected_public_ip VARCHAR(45) NULL,
  cover_color VARCHAR(20) NOT NULL DEFAULT '#2563eb',
  start_at DATETIME NULL,
  end_at DATETIME NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/Sao_Paulo',
  registration_enabled TINYINT(1) NOT NULL DEFAULT 1,
  notification_enabled TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('draft','active','finished') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_event_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  INDEX idx_event_status (status),
  INDEX idx_event_dates (start_at, end_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS participants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  correlation_id VARCHAR(100) NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  public_token VARCHAR(100) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  participant_type VARCHAR(80) NULL,
  registration_ip VARCHAR(45) NULL,
  user_agent TEXT NULL,
  status ENUM('active','unsubscribed','blocked') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_participant_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  INDEX idx_participant_event (event_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consent_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  consent_type ENUM('terms','privacy','notifications') NOT NULL,
  consent_version VARCHAR(30) NOT NULL DEFAULT '1.0',
  status ENUM('granted','revoked') NOT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent TEXT NULL,
  granted_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_consent_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
  CONSTRAINT fk_consent_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  INDEX idx_consent_lookup (participant_id, consent_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_subscriptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  endpoint VARCHAR(2048) NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  p256dh_key VARCHAR(255) NOT NULL,
  auth_key VARCHAR(255) NOT NULL,
  content_encoding VARCHAR(30) NOT NULL DEFAULT 'aes128gcm',
  device_hash CHAR(64) NULL,
  browser VARCHAR(80) NULL,
  platform VARCHAR(80) NULL,
  permission_status ENUM('granted','denied','default') NOT NULL DEFAULT 'granted',
  status ENUM('active','expired','revoked') NOT NULL DEFAULT 'active',
  last_seen_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  last_push_sent_at DATETIME NULL,
  last_push_received_at DATETIME NULL,
  last_push_http_status INT NULL,
  last_push_error TEXT NULL,
  CONSTRAINT fk_subscription_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
  CONSTRAINT fk_subscription_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  UNIQUE KEY uq_subscription_endpoint (endpoint_hash),
  INDEX idx_subscription_event (event_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  title VARCHAR(120) NOT NULL,
  body VARCHAR(500) NOT NULL,
  action_url VARCHAR(2048) NULL,
  icon_url VARCHAR(2048) NULL,
  image_url VARCHAR(2048) NULL,
  tag VARCHAR(80) NULL,
  audience_type ENUM('all','individual') NOT NULL DEFAULT 'all',
  participant_id BIGINT UNSIGNED NULL,
  scheduled_at DATETIME NOT NULL,
  expires_at DATETIME NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/Sao_Paulo',
  status ENUM('draft','scheduled','processing','sent','cancelled','failed') NOT NULL DEFAULT 'scheduled',
  sent_at DATETIME NULL,
  total_targets INT UNSIGNED NOT NULL DEFAULT 0,
  total_success INT UNSIGNED NOT NULL DEFAULT 0,
  total_failed INT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_message_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_message_admin FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_message_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE SET NULL,
  INDEX idx_message_due (status, scheduled_at),
  INDEX idx_message_event (event_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_deliveries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  correlation_id VARCHAR(100) NULL,
  message_id BIGINT UNSIGNED NOT NULL,
  subscription_id BIGINT UNSIGNED NOT NULL,
  participant_id BIGINT UNSIGNED NOT NULL,
  status ENUM('queued','processing','submitted','provider_accepted','provider_rejected','device_received','notification_displayed','clicked','failed','expired') NOT NULL DEFAULT 'queued',
  response_code INT NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  submitted_at DATETIME NULL,
  received_at DATETIME NULL,
  displayed_at DATETIME NULL,
  clicked_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_delivery_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_delivery_subscription FOREIGN KEY (subscription_id) REFERENCES push_subscriptions(id) ON DELETE CASCADE,
  CONSTRAINT fk_delivery_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
  UNIQUE KEY uq_delivery_target (message_id, subscription_id),
  INDEX idx_delivery_message_status (message_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  correlation_id VARCHAR(100) NULL,
  subscription_id BIGINT UNSIGNED NOT NULL,
  participant_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  message_id BIGINT UNSIGNED NULL,
  payload_json JSON NOT NULL,
  status ENUM('pending','consumed','expired','failed') NOT NULL DEFAULT 'pending',
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_queue_subscription FOREIGN KEY (subscription_id) REFERENCES push_subscriptions(id) ON DELETE CASCADE,
  CONSTRAINT fk_queue_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
  CONSTRAINT fk_queue_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_queue_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
  INDEX idx_queue_pull (subscription_id, status, expires_at, id),
  INDEX idx_queue_message (message_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_user_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(100) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  data_json JSON NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_audit_admin FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE SET NULL,
  INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS system_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  level VARCHAR(20) NOT NULL,
  channel VARCHAR(50) NOT NULL,
  message TEXT NOT NULL,
  request_id VARCHAR(100) NULL,
  correlation_id VARCHAR(100) NULL,
  admin_user_id BIGINT UNSIGNED NULL,
  event_id BIGINT UNSIGNED NULL,
  participant_id BIGINT UNSIGNED NULL,
  notification_message_id BIGINT UNSIGNED NULL,
  subscription_id BIGINT UNSIGNED NULL,
  route VARCHAR(255) NULL,
  http_method VARCHAR(10) NULL,
  http_code INT NULL,
  context_json LONGTEXT NULL,
  ip_hash VARCHAR(100) NULL,
  user_agent TEXT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_logs_created_at (created_at),
  INDEX idx_logs_level (level),
  INDEX idx_logs_channel (channel),
  INDEX idx_logs_correlation (correlation_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cron_heartbeat (
  id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  last_started_at DATETIME NULL,
  last_finished_at DATETIME NULL,
  last_success_at DATETIME NULL,
  last_error TEXT NULL,
  last_duration_ms INT UNSIGNED NULL,
  last_summary_json LONGTEXT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

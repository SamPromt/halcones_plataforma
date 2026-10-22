CREATE DATABASE IF NOT EXISTS halcones
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE halcones;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  email VARCHAR(190) NULL,
  password_hash VARCHAR(255) NULL,
  role ENUM('CUSTOMER', 'DRIVER', 'OPERATOR', 'ADMIN') NOT NULL DEFAULT 'CUSTOMER',
  account_status ENUM('ACTIVE', 'SUSPENDED') NOT NULL DEFAULT 'ACTIVE',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_phone (phone),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role_status (role, account_status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS drivers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  driver_code VARCHAR(24) NOT NULL,
  vehicle_type ENUM('CAR', 'MOTORCYCLE', 'VAN', 'OTHER') NOT NULL DEFAULT 'CAR',
  vehicle_model VARCHAR(100) NOT NULL,
  plate VARCHAR(12) NOT NULL,
  status ENUM('AVAILABLE', 'BUSY', 'OUT_OF_SERVICE') NOT NULL DEFAULT 'OUT_OF_SERVICE',
  out_of_service_reason VARCHAR(255) NULL,
  last_latitude DECIMAL(10, 7) NULL,
  last_longitude DECIMAL(10, 7) NULL,
  last_location_accuracy DECIMAL(8, 2) NULL,
  last_location_at DATETIME(3) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_drivers_user (user_id),
  UNIQUE KEY uq_drivers_code (driver_code),
  UNIQUE KEY uq_drivers_plate (plate),
  KEY idx_drivers_status_location (status, last_location_at),
  CONSTRAINT fk_drivers_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS services (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  assigned_driver_id BIGINT UNSIGNED NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  channel ENUM('WEB', 'WHATSAPP', 'OPERATOR', 'DRIVER') NOT NULL,
  external_request_id VARCHAR(128) NULL,
  origin_address VARCHAR(500) NOT NULL,
  origin_latitude DECIMAL(10, 7) NULL,
  origin_longitude DECIMAL(10, 7) NULL,
  destination_address VARCHAR(500) NOT NULL,
  destination_latitude DECIMAL(10, 7) NULL,
  destination_longitude DECIMAL(10, 7) NULL,
  estimated_fare DECIMAL(12, 2) NULL,
  final_fare DECIMAL(12, 2) NULL,
  status ENUM('REQUESTED', 'PENDING_ASSIGNMENT', 'OFFERED', 'ACCEPTED', 'EN_ROUTE', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED', 'EXPIRED', 'REJECTED') NOT NULL DEFAULT 'REQUESTED',
  cancellation_reason VARCHAR(255) NULL,
  requested_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  accepted_at DATETIME(3) NULL,
  arrived_at DATETIME(3) NULL,
  started_at DATETIME(3) NULL,
  completed_at DATETIME(3) NULL,
  cancelled_at DATETIME(3) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_services_public_id (public_id),
  UNIQUE KEY uq_services_channel_external (channel, external_request_id),
  KEY idx_services_status_requested (status, requested_at),
  KEY idx_services_driver_status (assigned_driver_id, status),
  KEY idx_services_customer_requested (customer_id, requested_at),
  CONSTRAINT fk_services_customer FOREIGN KEY (customer_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_services_assigned_driver FOREIGN KEY (assigned_driver_id) REFERENCES drivers (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_services_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_assignments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_id BIGINT UNSIGNED NOT NULL,
  driver_id BIGINT UNSIGNED NOT NULL,
  assigned_by_user_id BIGINT UNSIGNED NULL,
  assignment_mode ENUM('AUTOMATIC', 'MANUAL') NOT NULL,
  status ENUM('OFFERED', 'ACCEPTED', 'REJECTED', 'EXPIRED', 'CANCELLED', 'COMPLETED') NOT NULL,
  offered_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  responded_at DATETIME(3) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_assignments_service (service_id, created_at),
  KEY idx_assignments_driver (driver_id, created_at),
  CONSTRAINT fk_assignments_service FOREIGN KEY (service_id) REFERENCES services (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_assignments_driver FOREIGN KEY (driver_id) REFERENCES drivers (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_assignments_operator FOREIGN KEY (assigned_by_user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS current_driver_assignments (
  driver_id BIGINT UNSIGNED NOT NULL,
  service_id BIGINT UNSIGNED NOT NULL,
  assignment_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (driver_id),
  UNIQUE KEY uq_current_assignment_service (service_id),
  UNIQUE KEY uq_current_assignment_id (assignment_id),
  CONSTRAINT fk_current_driver FOREIGN KEY (driver_id) REFERENCES drivers (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_current_service FOREIGN KEY (service_id) REFERENCES services (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_current_assignment FOREIGN KEY (assignment_id) REFERENCES service_assignments (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS driver_status_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  driver_id BIGINT UNSIGNED NOT NULL,
  from_status ENUM('AVAILABLE', 'BUSY', 'OUT_OF_SERVICE') NULL,
  to_status ENUM('AVAILABLE', 'BUSY', 'OUT_OF_SERVICE') NOT NULL,
  changed_by_user_id BIGINT UNSIGNED NULL,
  reason VARCHAR(255) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_driver_status_history (driver_id, created_at),
  CONSTRAINT fk_driver_history_driver FOREIGN KEY (driver_id) REFERENCES drivers (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_driver_history_actor FOREIGN KEY (changed_by_user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_status_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_id BIGINT UNSIGNED NOT NULL,
  from_status ENUM('REQUESTED', 'PENDING_ASSIGNMENT', 'OFFERED', 'ACCEPTED', 'EN_ROUTE', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED', 'EXPIRED', 'REJECTED') NULL,
  to_status ENUM('REQUESTED', 'PENDING_ASSIGNMENT', 'OFFERED', 'ACCEPTED', 'EN_ROUTE', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED', 'EXPIRED', 'REJECTED') NOT NULL,
  changed_by_user_id BIGINT UNSIGNED NULL,
  reason VARCHAR(255) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_service_status_history (service_id, created_at),
  CONSTRAINT fk_service_history_service FOREIGN KEY (service_id) REFERENCES services (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_service_history_actor FOREIGN KEY (changed_by_user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS allowed_status_transitions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_type ENUM('DRIVER', 'SERVICE') NOT NULL,
  from_status VARCHAR(32) NOT NULL,
  to_status VARCHAR(32) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_allowed_transition (entity_type, from_status, to_status)
) ENGINE=InnoDB;

INSERT IGNORE INTO allowed_status_transitions (entity_type, from_status, to_status) VALUES
  ('DRIVER', 'AVAILABLE', 'BUSY'),
  ('DRIVER', 'AVAILABLE', 'OUT_OF_SERVICE'),
  ('DRIVER', 'BUSY', 'AVAILABLE'),
  ('DRIVER', 'OUT_OF_SERVICE', 'AVAILABLE'),
  ('SERVICE', 'REQUESTED', 'PENDING_ASSIGNMENT'),
  ('SERVICE', 'REQUESTED', 'CANCELLED'),
  ('SERVICE', 'PENDING_ASSIGNMENT', 'OFFERED'),
  ('SERVICE', 'PENDING_ASSIGNMENT', 'CANCELLED'),
  ('SERVICE', 'OFFERED', 'ACCEPTED'),
  ('SERVICE', 'OFFERED', 'REJECTED'),
  ('SERVICE', 'OFFERED', 'EXPIRED'),
  ('SERVICE', 'OFFERED', 'CANCELLED'),
  ('SERVICE', 'ACCEPTED', 'EN_ROUTE'),
  ('SERVICE', 'ACCEPTED', 'CANCELLED'),
  ('SERVICE', 'EN_ROUTE', 'IN_PROGRESS'),
  ('SERVICE', 'EN_ROUTE', 'CANCELLED'),
  ('SERVICE', 'IN_PROGRESS', 'COMPLETED'),
  ('SERVICE', 'IN_PROGRESS', 'CANCELLED');

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_id BIGINT UNSIGNED NOT NULL,
  recorded_by_user_id BIGINT UNSIGNED NOT NULL,
  method ENUM('CASH', 'TRANSFER') NOT NULL,
  amount DECIMAL(12, 2) NOT NULL,
  status ENUM('RECORDED', 'VOIDED', 'REFUNDED') NOT NULL DEFAULT 'RECORDED',
  reference VARCHAR(120) NULL,
  idempotency_key VARCHAR(100) NOT NULL,
  paid_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_service (service_id),
  UNIQUE KEY uq_payment_idempotency (idempotency_key),
  KEY idx_payments_paid_at (paid_at),
  CONSTRAINT fk_payments_service FOREIGN KEY (service_id) REFERENCES services (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_payments_actor FOREIGN KEY (recorded_by_user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS driver_locations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  driver_id BIGINT UNSIGNED NOT NULL,
  latitude DECIMAL(10, 7) NOT NULL,
  longitude DECIMAL(10, 7) NOT NULL,
  accuracy_meters DECIMAL(8, 2) NULL,
  speed_meters_per_second DECIMAL(8, 2) NULL,
  heading_degrees DECIMAL(6, 2) NULL,
  recorded_at DATETIME(3) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_locations_driver_recorded (driver_id, recorded_at),
  CONSTRAINT fk_locations_driver FOREIGN KEY (driver_id) REFERENCES drivers (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS webhook_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider ENUM('META_WHATSAPP') NOT NULL,
  external_message_id VARCHAR(160) NOT NULL,
  sender_phone VARCHAR(20) NULL,
  payload JSON NOT NULL,
  processing_status ENUM('RECEIVED', 'PROCESSED', 'FAILED') NOT NULL DEFAULT 'RECEIVED',
  received_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  processed_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_webhook_external_message (provider, external_message_id),
  KEY idx_webhook_processing (processing_status, received_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS outbox_events (
  id CHAR(36) NOT NULL,
  event_name VARCHAR(100) NOT NULL,
  aggregate_type VARCHAR(40) NOT NULL,
  aggregate_id BIGINT UNSIGNED NOT NULL,
  payload JSON NOT NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  published_at DATETIME(3) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_outbox_pending (published_at, available_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS push_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  endpoint TEXT NOT NULL,
  public_key VARCHAR(255) NOT NULL,
  auth_token VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_push_endpoint_hash (endpoint_hash),
  KEY idx_push_user (user_id),
  CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

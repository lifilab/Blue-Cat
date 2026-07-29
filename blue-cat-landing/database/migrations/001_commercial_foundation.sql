CREATE DATABASE IF NOT EXISTS blue_cat_commercial CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE blue_cat_commercial;

CREATE TABLE customers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  business_name VARCHAR(160) NOT NULL,
  contact_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  country VARCHAR(80) NOT NULL,
  city VARCHAR(100) NOT NULL,
  tax_id VARCHAR(50) NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_customers_email (email)
) ENGINE=InnoDB;

CREATE TABLE purchase_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tracking_id VARCHAR(40) NOT NULL UNIQUE,
  customer_id BIGINT UNSIGNED NOT NULL,
  plan_id ENUM('pyme','enterprise') NOT NULL,
  estimated_branches SMALLINT UNSIGNED NOT NULL,
  wants_cloud_sync BOOLEAN NOT NULL DEFAULT FALSE,
  message TEXT NULL,
  status ENUM('draft','pending_quote','pending_payment','payment_reported','under_review','approved','rejected','license_generated','download_available','completed','cancelled') NOT NULL,
  request_hash CHAR(64) NOT NULL UNIQUE,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_purchase_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  INDEX idx_purchase_status_created (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE audit_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id CHAR(36) NOT NULL,
  aggregate_type VARCHAR(50) NOT NULL,
  aggregate_id VARCHAR(64) NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  metadata_json JSON NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_audit_aggregate (aggregate_type, aggregate_id, created_at)
) ENGINE=InnoDB;

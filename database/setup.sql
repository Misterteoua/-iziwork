-- Iziwork Database Setup
-- Run this SQL file to create the database and tables

-- Create database
CREATE DATABASE IF NOT EXISTS iziwork CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE iziwork;

-- Admin Users table
CREATE TABLE IF NOT EXISTS admin_users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(255) DEFAULT 'admin',
    remember_token VARCHAR(100) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB;

-- Forms table
CREATE TABLE IF NOT EXISTS forms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    token VARCHAR(32) UNIQUE NOT NULL,
    open_date TIMESTAMP NULL,
    close_date TIMESTAMP NULL,
    max_submissions INT NULL,
    is_anonymous BOOLEAN DEFAULT FALSE,
    status ENUM('active', 'inactive') DEFAULT 'inactive',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Form Fields table
CREATE TABLE IF NOT EXISTS form_fields (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id BIGINT UNSIGNED NOT NULL,
    field_label VARCHAR(255) NOT NULL,
    field_type ENUM('text', 'email', 'tel', 'file', 'select', 'checkbox') NOT NULL,
    required BOOLEAN DEFAULT TRUE,
    `order` INT DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Submissions table
CREATE TABLE IF NOT EXISTS submissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id BIGINT UNSIGNED NOT NULL,
    student_name VARCHAR(255) NULL,
    student_email VARCHAR(255) NULL,
    student_phone VARCHAR(20) NULL,
    student_major VARCHAR(255) NULL,
    anonymous_code VARCHAR(255) UNIQUE NULL,
    receipt_token VARCHAR(64) UNIQUE NULL,
    status ENUM('pending', 'validated') DEFAULT 'pending',
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Submission Files table
CREATE TABLE IF NOT EXISTS submission_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id BIGINT UNSIGNED NOT NULL,
    field_label VARCHAR(255) NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    mime_type VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Insert default admin user
-- Password: password (hashed with bcrypt)
INSERT INTO admin_users (username, email, password_hash, role, created_at, updated_at) VALUES
('admin', 'admin@iziwork.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', NOW(), NOW());

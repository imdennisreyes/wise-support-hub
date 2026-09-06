-- Wise Support Hub - MySQL 8 deployment schema
CREATE DATABASE IF NOT EXISTS wise_support_hub CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE wise_support_hub;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS article_versions, article_feedback, search_logs, related_articles, article_tags,
    knowledge_articles, tags, carriers, categories, modules, systems, users, roles;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role_active (role_id, is_active),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

CREATE TABLE systems (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    code VARCHAR(50) NULL UNIQUE,
    description VARCHAR(500) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_systems_active_name (is_active, name)
) ENGINE=InnoDB;

CREATE TABLE modules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    system_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(50) NULL,
    description VARCHAR(500) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_modules_system_name (system_id, name),
    INDEX idx_modules_active (is_active),
    CONSTRAINT fk_modules_system FOREIGN KEY (system_id) REFERENCES systems(id)
) ENGINE=InnoDB;

CREATE TABLE categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    code VARCHAR(50) NULL UNIQUE,
    description VARCHAR(500) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_categories_active_name (is_active, name)
) ENGINE=InnoDB;

CREATE TABLE carriers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    code VARCHAR(50) NULL UNIQUE,
    description VARCHAR(500) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_carriers_active_name (is_active, name)
) ENGINE=InnoDB;

CREATE TABLE tags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    code VARCHAR(50) NULL,
    description VARCHAR(500) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tags_active_name (is_active, name)
) ENGINE=InnoDB;

CREATE TABLE knowledge_articles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type ENUM('Article','Error','SOP','Request') NOT NULL DEFAULT 'Article',
    title VARCHAR(255) NOT NULL,
    system_id BIGINT UNSIGNED NULL,
    module_id BIGINT UNSIGNED NULL,
    category_id BIGINT UNSIGNED NULL,
    carrier_id BIGINT UNSIGNED NULL,
    error_code VARCHAR(100) NULL,
    severity ENUM('Critical','High','Medium','Low') NULL,
    error_message TEXT NULL,
    description TEXT NOT NULL,
    root_cause TEXT NULL,
    resolution MEDIUMTEXT NULL,
    sop_steps MEDIUMTEXT NULL,
    validation_steps TEXT NULL,
    escalation_path TEXT NULL,
    keywords TEXT NULL,
    status ENUM('Draft','Published','Under Review','Archived') NOT NULL DEFAULT 'Draft',
    owner_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    is_sample BOOLEAN NOT NULL DEFAULT FALSE,
    view_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    INDEX idx_articles_type_status_deleted (type, status, deleted_at),
    INDEX idx_articles_system_status (system_id, status),
    INDEX idx_articles_carrier_status (carrier_id, status),
    INDEX idx_articles_category (category_id),
    INDEX idx_articles_severity (severity),
    INDEX idx_articles_updated (updated_at),
    INDEX idx_articles_error_code (error_code),
    FULLTEXT INDEX ft_articles_search (title, error_code, error_message, description, root_cause, resolution, sop_steps, keywords),
    CONSTRAINT fk_articles_system FOREIGN KEY (system_id) REFERENCES systems(id),
    CONSTRAINT fk_articles_module FOREIGN KEY (module_id) REFERENCES modules(id),
    CONSTRAINT fk_articles_category FOREIGN KEY (category_id) REFERENCES categories(id),
    CONSTRAINT fk_articles_carrier FOREIGN KEY (carrier_id) REFERENCES carriers(id),
    CONSTRAINT fk_articles_owner FOREIGN KEY (owner_id) REFERENCES users(id),
    CONSTRAINT fk_articles_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_articles_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE article_tags (
    article_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (article_id, tag_id),
    INDEX idx_article_tags_tag (tag_id),
    CONSTRAINT fk_article_tags_article FOREIGN KEY (article_id) REFERENCES knowledge_articles(id) ON DELETE CASCADE,
    CONSTRAINT fk_article_tags_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE related_articles (
    article_id BIGINT UNSIGNED NOT NULL,
    related_article_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (article_id, related_article_id),
    INDEX idx_related_reverse (related_article_id),
    CONSTRAINT chk_related_different CHECK (article_id <> related_article_id),
    CONSTRAINT fk_related_article FOREIGN KEY (article_id) REFERENCES knowledge_articles(id) ON DELETE CASCADE,
    CONSTRAINT fk_related_target FOREIGN KEY (related_article_id) REFERENCES knowledge_articles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE search_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    query VARCHAR(500) NOT NULL,
    result_count INT UNSIGNED NOT NULL DEFAULT 0,
    selected_article_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    selected_at DATETIME NULL,
    INDEX idx_search_created (created_at),
    INDEX idx_search_result_count (result_count, created_at),
    INDEX idx_search_user (user_id),
    CONSTRAINT fk_search_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_search_selected FOREIGN KEY (selected_article_id) REFERENCES knowledge_articles(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE article_feedback (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    article_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    is_helpful BOOLEAN NOT NULL,
    reason VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_feedback_article_user (article_id, user_id),
    INDEX idx_feedback_helpful (is_helpful, created_at),
    CONSTRAINT fk_feedback_article FOREIGN KEY (article_id) REFERENCES knowledge_articles(id) ON DELETE CASCADE,
    CONSTRAINT fk_feedback_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE article_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    article_id BIGINT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    snapshot_json JSON NOT NULL,
    change_summary VARCHAR(500) NOT NULL,
    changed_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_article_version (article_id, version_number),
    INDEX idx_versions_created (created_at),
    CONSTRAINT fk_versions_article FOREIGN KEY (article_id) REFERENCES knowledge_articles(id) ON DELETE CASCADE,
    CONSTRAINT fk_versions_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

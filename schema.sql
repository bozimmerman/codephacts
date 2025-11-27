CREATE DATABASE IF NOT EXISTS codephacts CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE code_analyzer;

-- Projects table
CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    source_type ENUM('git', 'svn') NOT NULL,
    source_url VARCHAR(500) NOT NULL,
    last_commit VARCHAR(255) NULL,
    excluded_dirs TEXT NULL,
    manager VARCHAR(255) DEFAULT NULL,
    auth_type ENUM('none', 'basic', 'ssh') DEFAULT 'none',
    auth_username VARCHAR(255) NULL,
    auth_password TEXT NULL,
    auth_ssh_key_path VARCHAR(500),
    last_updated DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_source_type (source_type),
    INDEX idx_last_updated (last_updated)
) ENGINE=InnoDB;

-- Project commits table
CREATE TABLE IF NOT EXISTS commits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    commit_hash VARCHAR(255) NOT NULL,
    commit_user VARCHAR(255) DEFAULT NULL,
    commit_timestamp DATETIME NOT NULL,
    processed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_project_commit (project_id, commit_hash),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_project_id (project_id),
    INDEX idx_commit_timestamp (commit_timestamp)
) ENGINE=InnoDB;

-- Project statistics table (per commit!)
CREATE TABLE IF NOT EXISTS statistics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    commit_id INT NOT NULL COMMENT 'Links to commits table',
    language VARCHAR(50) NOT NULL,
    total_lines INT DEFAULT 0,
    code_lines INT DEFAULT 0,
    code_statements INT DEFAULT 0,
    weighted_code_statements INT DEFAULT 0,
    weighted_code_lines INT DEFAULT 0,
    blank_lines INT DEFAULT 0,
    comment_lines INT DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ncloc INT DEFAULT 0,
    UNIQUE KEY unique_commit_language (commit_id, language),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (commit_id) REFERENCES commits(id) ON DELETE CASCADE,
    INDEX idx_project_id (project_id),
    INDEX idx_commit_id (commit_id),
    INDEX idx_language (language)
) ENGINE=InnoDB;

-- Sample test data
--INSERT INTO projects (name, source_type, source_url, excluded_dirs) VALUES('Test Project', 'git', 'https://github.com/your-username/your-repo.git', 'node_modules,vendor,.idea,build,dist');

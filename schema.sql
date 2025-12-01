CREATE DATABASE IF NOT EXISTS codephacts CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE codephacts;

CREATE TABLE IF NOT EXISTS projects 
(
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    source_type VARCHAR(10) NOT NULL,
    source_url VARCHAR(500) NOT NULL,
    last_commit VARCHAR(255) NULL,
    excluded_dirs TEXT NULL,
    manager VARCHAR(255) DEFAULT NULL,
    auth_type VARCHAR(10) DEFAULT 'none',
    auth_username VARCHAR(255) NULL,
    auth_password TEXT NULL,
    auth_ssh_key_path VARCHAR(500),
    last_updated DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_source_type ON projects(source_type);
CREATE INDEX idx_last_updated ON projects(last_updated);

CREATE TABLE IF NOT EXISTS commits 
(
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    commit_hash VARCHAR(255) NOT NULL,
    commit_user VARCHAR(255) DEFAULT NULL,
    commit_timestamp DATETIME NOT NULL,
    processed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    UNIQUE(project_id, commit_hash)
) ENGINE=InnoDB;

CREATE INDEX idx_commits_project_id ON commits(project_id);
CREATE INDEX idx_commits_timestamp ON commits(commit_timestamp);

CREATE TABLE IF NOT EXISTS statistics 
(
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    commit_id INT NOT NULL,
    language VARCHAR(50) NOT NULL,
    total_lines INT DEFAULT 0,
    code_lines INT DEFAULT 0,
    code_statements INT DEFAULT 0,
    weighted_code_statements INT DEFAULT 0,
    weighted_code_lines INT DEFAULT 0,
    blank_lines INT DEFAULT 0,
    comment_lines INT DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    ncloc INT DEFAULT 0,
    cyclomatic_complexity INT DEFAULT 0,
    cognitive_complexity INT DEFAULT 0,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (commit_id) REFERENCES commits(id) ON DELETE CASCADE,
    UNIQUE(commit_id, language)
) ENGINE=InnoDB;

CREATE INDEX idx_statistics_project_id ON statistics(project_id);
CREATE INDEX idx_statistics_commit_id ON statistics(commit_id);
CREATE INDEX idx_statistics_language ON statistics(language);
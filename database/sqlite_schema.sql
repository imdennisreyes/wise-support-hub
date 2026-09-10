CREATE TABLE roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    role_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    last_login_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id)
);
CREATE TABLE systems (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    code TEXT,
    description TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE modules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    system_id INTEGER,
    name TEXT NOT NULL,
    code TEXT,
    description TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(system_id, name), FOREIGN KEY (system_id) REFERENCES systems(id)
);
CREATE TABLE categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    code TEXT,
    description TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE carriers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    code TEXT,
    description TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    code TEXT,
    description TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE knowledge_articles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL CHECK(type IN ('Article','Error','SOP','Request')),
    title TEXT NOT NULL,
    system_id INTEGER,
    module_id INTEGER,
    category_id INTEGER,
    carrier_id INTEGER,
    error_code TEXT,
    severity TEXT CHECK(severity IN ('Critical','High','Medium','Low')),
    error_message TEXT,
    description TEXT NOT NULL,
    root_cause TEXT,
    resolution TEXT,
    sop_steps TEXT,
    validation_steps TEXT,
    escalation_path TEXT,
    keywords TEXT,
    status TEXT NOT NULL DEFAULT 'Draft' CHECK(status IN ('Draft','Published','Under Review','Archived')),
    owner_id INTEGER,
    created_by INTEGER,
    updated_by INTEGER,
    is_sample INTEGER NOT NULL DEFAULT 0,
    view_count INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TEXT,
    FOREIGN KEY (system_id) REFERENCES systems(id), FOREIGN KEY (module_id) REFERENCES modules(id),
    FOREIGN KEY (category_id) REFERENCES categories(id), FOREIGN KEY (carrier_id) REFERENCES carriers(id),
    FOREIGN KEY (owner_id) REFERENCES users(id), FOREIGN KEY (created_by) REFERENCES users(id), FOREIGN KEY (updated_by) REFERENCES users(id)
);
CREATE INDEX idx_articles_type_status ON knowledge_articles(type, status);
CREATE INDEX idx_articles_system ON knowledge_articles(system_id);
CREATE INDEX idx_articles_carrier ON knowledge_articles(carrier_id);
CREATE INDEX idx_articles_category ON knowledge_articles(category_id);
CREATE INDEX idx_articles_updated ON knowledge_articles(updated_at);
CREATE TABLE article_tags (
    article_id INTEGER NOT NULL,
    tag_id INTEGER NOT NULL,
    PRIMARY KEY(article_id, tag_id),
    FOREIGN KEY (article_id) REFERENCES knowledge_articles(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
);
CREATE TABLE related_articles (
    article_id INTEGER NOT NULL,
    related_article_id INTEGER NOT NULL,
    PRIMARY KEY(article_id, related_article_id),
    CHECK(article_id <> related_article_id),
    FOREIGN KEY (article_id) REFERENCES knowledge_articles(id) ON DELETE CASCADE,
    FOREIGN KEY (related_article_id) REFERENCES knowledge_articles(id) ON DELETE CASCADE
);
CREATE TABLE search_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    query TEXT NOT NULL,
    result_count INTEGER NOT NULL DEFAULT 0,
    selected_article_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    selected_at TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id), FOREIGN KEY (selected_article_id) REFERENCES knowledge_articles(id)
);
CREATE INDEX idx_search_logs_created ON search_logs(created_at);
CREATE TABLE article_feedback (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL,
    user_id INTEGER,
    is_helpful INTEGER NOT NULL,
    reason TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(article_id, user_id),
    FOREIGN KEY (article_id) REFERENCES knowledge_articles(id), FOREIGN KEY (user_id) REFERENCES users(id)
);
CREATE TABLE article_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL,
    version_number INTEGER NOT NULL,
    snapshot_json TEXT NOT NULL,
    change_summary TEXT NOT NULL,
    changed_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(article_id, version_number),
    FOREIGN KEY (article_id) REFERENCES knowledge_articles(id), FOREIGN KEY (changed_by) REFERENCES users(id)
);
CREATE TABLE api_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    is_active INTEGER NOT NULL DEFAULT 1,
    last_used_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

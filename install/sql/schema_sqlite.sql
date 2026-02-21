CREATE TABLE IF NOT EXISTS nodes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    node_id TEXT NOT NULL,
    node_name TEXT,
    status TEXT DEFAULT 'offline',
    users INTEGER DEFAULT 0,
    last_seen TEXT
);

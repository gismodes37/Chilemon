CREATE TABLE IF NOT EXISTS nodes (
  node_id     TEXT PRIMARY KEY,
  status      TEXT NOT NULL DEFAULT 'unknown',
  signal      TEXT,
  system      TEXT,
  uptime      TEXT,
  last_seen   TEXT NOT NULL,
  raw_stats   TEXT
);

CREATE TABLE IF NOT EXISTS node_links (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  node_id     TEXT NOT NULL,
  linked_node TEXT NOT NULL,
  direction   TEXT,
  created_at  TEXT NOT NULL,
  UNIQUE(node_id, linked_node)
);




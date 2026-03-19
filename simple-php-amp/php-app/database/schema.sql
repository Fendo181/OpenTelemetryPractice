-- PHPerKaigi 2026デモ用 Todoテーブル定義
-- シンプルなTodoアプリのスキーマ

CREATE TABLE IF NOT EXISTS todos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    completed INTEGER DEFAULT 0,  -- 0: 未完了, 1: 完了
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- サンプルデータ（デモ用）
INSERT INTO todos (title, completed) VALUES
    ('PHPerKaigi 2026の資料作成', 0),
    ('OpenTelemetryのデモ準備', 0),
    ('Jaegerでトレース確認', 1);

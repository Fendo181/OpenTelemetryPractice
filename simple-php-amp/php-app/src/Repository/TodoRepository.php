<?php

namespace SimplePhpApm\Repository;

use SimplePhpApm\Telemetry\TelemetryInitializer;
use OpenTelemetry\API\Trace\SpanKind;

/**
 * TodoRepository - SQLite操作 + DB Span計装
 *
 * APMで最も重要な計装ポイントの1つ。
 * データベースクエリを個別のSpanとして記録する。
 *
 * このクラスでは以下を実装:
 * 1. SQLiteでのCRUD操作
 * 2. 各クエリをChild Span（SpanKind::CLIENT）としてラップ
 * 3. Span属性にSQLを記録（db.statement）
 *
 * 商用APM（Datadog/New Relic）は、PDO/MySQLiをフックしてこれを自動化している。
 *
 * PHPerKaigi 2026デモ用
 */
class TodoRepository
{
    private \PDO $db;

    public function __construct()
    {
        // SQLiteデータベース接続
        $dbPath = __DIR__ . '/../../database/todos.db';
        $this->db = new \PDO("sqlite:{$dbPath}");
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // テーブル初期化（初回のみ）
        $this->initDatabase();
    }

    /**
     * データベース初期化（テーブル作成）
     */
    private function initDatabase(): void
    {
        $schemaPath = __DIR__ . '/../../database/schema.sql';
        if (file_exists($schemaPath)) {
            $schema = file_get_contents($schemaPath);
            $this->db->exec($schema);
        }
    }

    /**
     * Todo一覧取得
     *
     * @return array
     */
    public function findAll(): array
    {
        $tracer = TelemetryInitializer::getTracer();

        // DB Span作成（SpanKind::CLIENT = DBクライアント）
        $span = $tracer->spanBuilder('db.query: SELECT todos')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();

        $scope = $span->activate();

        try {
            // DB属性を記録（OpenTelemetry Semantic Conventions）
            $span->setAttribute('db.system', 'sqlite');
            $span->setAttribute('db.name', 'todos.db');
            $span->setAttribute('db.statement', 'SELECT * FROM todos ORDER BY created_at DESC');

            // クエリ実行
            $stmt = $this->db->query('SELECT * FROM todos ORDER BY created_at DESC');
            $todos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // 結果を属性に記録（デバッグ用）
            $span->setAttribute('db.rows_affected', count($todos));

            return $todos;

        } finally {
            $span->end();
            $scope->detach();
        }
    }

    /**
     * 特定Todo取得
     *
     * @param int $id
     * @return array|null
     */
    public function findById(int $id): ?array
    {
        $tracer = TelemetryInitializer::getTracer();

        $span = $tracer->spanBuilder('db.query: SELECT todo by id')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();

        $scope = $span->activate();

        try {
            $span->setAttribute('db.system', 'sqlite');
            $span->setAttribute('db.name', 'todos.db');
            $span->setAttribute('db.statement', 'SELECT * FROM todos WHERE id = ?');
            $span->setAttribute('db.todo_id', $id);

            $stmt = $this->db->prepare('SELECT * FROM todos WHERE id = ?');
            $stmt->execute([$id]);
            $todo = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $todo ?: null;

        } finally {
            $span->end();
            $scope->detach();
        }
    }

    /**
     * Todo作成
     *
     * @param string $title
     * @return int 作成されたTodoのID
     */
    public function create(string $title): int
    {
        $tracer = TelemetryInitializer::getTracer();

        $span = $tracer->spanBuilder('db.query: INSERT todo')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();

        $scope = $span->activate();

        try {
            $span->setAttribute('db.system', 'sqlite');
            $span->setAttribute('db.name', 'todos.db');
            $span->setAttribute('db.statement', 'INSERT INTO todos (title, completed) VALUES (?, ?)');
            $span->setAttribute('db.todo_title', $title);

            $stmt = $this->db->prepare('INSERT INTO todos (title, completed) VALUES (?, ?)');
            $stmt->execute([$title, 0]);

            $id = (int) $this->db->lastInsertId();
            $span->setAttribute('db.todo_id', $id);

            return $id;

        } finally {
            $span->end();
            $scope->detach();
        }
    }

    /**
     * Todo更新
     *
     * @param int $id
     * @param bool $completed
     */
    public function update(int $id, bool $completed): void
    {
        $tracer = TelemetryInitializer::getTracer();

        $span = $tracer->spanBuilder('db.query: UPDATE todo')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();

        $scope = $span->activate();

        try {
            $span->setAttribute('db.system', 'sqlite');
            $span->setAttribute('db.name', 'todos.db');
            $span->setAttribute('db.statement', 'UPDATE todos SET completed = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $span->setAttribute('db.todo_id', $id);
            $span->setAttribute('db.todo_completed', $completed);

            $stmt = $this->db->prepare('UPDATE todos SET completed = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([$completed ? 1 : 0, $id]);

        } finally {
            $span->end();
            $scope->detach();
        }
    }

    /**
     * Todo削除
     *
     * @param int $id
     */
    public function delete(int $id): void
    {
        $tracer = TelemetryInitializer::getTracer();

        $span = $tracer->spanBuilder('db.query: DELETE todo')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();

        $scope = $span->activate();

        try {
            $span->setAttribute('db.system', 'sqlite');
            $span->setAttribute('db.name', 'todos.db');
            $span->setAttribute('db.statement', 'DELETE FROM todos WHERE id = ?');
            $span->setAttribute('db.todo_id', $id);

            $stmt = $this->db->prepare('DELETE FROM todos WHERE id = ?');
            $stmt->execute([$id]);

        } finally {
            $span->end();
            $scope->detach();
        }
    }
}

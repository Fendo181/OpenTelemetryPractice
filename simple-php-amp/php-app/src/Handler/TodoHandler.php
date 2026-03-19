<?php

namespace SimplePhpApm\Handler;

use SimplePhpApm\Repository\TodoRepository;
use SimplePhpApm\Telemetry\TelemetryInitializer;
use OpenTelemetry\API\Trace\SpanKind;

/**
 * TodoHandler - RESTエンドポイントのビジネスロジック
 *
 * シンプルなTodo CRUDを実装。
 * このクラスでは以下を実装:
 * 1. エンドポイント処理（GET/POST/PUT/DELETE）
 * 2. バリデーション（空タイトルチェック）
 * 3. 構造化ログ（Trace ID付き）
 * 4. Context Propagation（ダミー外部サービス呼び出し）
 *
 * PHPerKaigi 2026デモ用
 */
class TodoHandler
{
    private TodoRepository $repository;

    /**
     * @param TodoRepository|null $repository テスト時にモックを注入できる（t_wada TDD: 依存性注入によるセアム）
     */
    public function __construct(?TodoRepository $repository = null)
    {
        $this->repository = $repository ?? new TodoRepository();
    }

    /**
     * GET /todos - Todo一覧取得
     *
     * @return array
     */
    public function list(): array
    {
        $logger = TelemetryInitializer::getLogger();

        // 構造化ログ（INFO）
        // Trace IDが自動で含まれるので、ログからトレースにジャンプできる
        $logger->info('Todo一覧取得リクエスト');

        $todos = $this->repository->findAll();

        $logger->info('Todo一覧取得完了', [
            'count' => count($todos),
        ]);

        return ['todos' => $todos];
    }

    /**
     * GET /todos/{id} - 特定Todo取得
     *
     * @param int $id
     * @return array
     */
    public function get(int $id): array
    {
        $logger = TelemetryInitializer::getLogger();
        $logger->info('Todo取得リクエスト', ['id' => $id]);

        $todo = $this->repository->findById($id);

        if ($todo === null) {
            // 404 Not Found
            $logger->warning('Todo が見つかりません', ['id' => $id]);
            http_response_code(404);
            return [
                'error' => 'Todo not found',
                'id' => $id,
            ];
        }

        $logger->info('Todo取得完了', ['id' => $id]);
        return ['todo' => $todo];
    }

    /**
     * POST /todos - Todo作成
     *
     * @param array $input
     * @return array
     */
    public function create(array $input): array
    {
        $logger = TelemetryInitializer::getLogger();
        $logger->info('Todo作成リクエストを受信', ['input' => $input]);

        // バリデーション（タイトル必須・空白のみも無効）
        if (empty(trim($input['title'] ?? ''))) {
            $logger->error('バリデーションエラー: タイトルが空です');
            http_response_code(400);
            return [
                'error' => 'Validation failed',
                'message' => 'Title is required',
            ];
        }

        // ダミー外部サービス呼び出し（Context Propagation デモ用）
        // 実際にはHTTPリクエストを送らないが、traceparentヘッダーの生成ロジックを示す
        $this->notifyExternalService($input['title']);

        // Todo作成
        $id = $this->repository->create($input['title']);

        $logger->info('Todo作成完了', ['id' => $id, 'title' => $input['title']]);

        http_response_code(201);
        return [
            'id' => $id,
            'title' => $input['title'],
            'completed' => false,
        ];
    }

    /**
     * PUT /todos/{id} - Todo更新
     *
     * @param int $id
     * @param array $input
     * @return array
     */
    public function update(int $id, array $input): array
    {
        $logger = TelemetryInitializer::getLogger();
        $logger->info('Todo更新リクエスト', ['id' => $id, 'input' => $input]);

        $todo = $this->repository->findById($id);
        if ($todo === null) {
            $logger->warning('Todo が見つかりません', ['id' => $id]);
            http_response_code(404);
            return [
                'error' => 'Todo not found',
                'id' => $id,
            ];
        }

        // 完了フラグを更新
        $completed = $input['completed'] ?? false;
        $this->repository->update($id, $completed);

        $logger->info('Todo更新完了', ['id' => $id, 'completed' => $completed]);

        return [
            'id' => $id,
            'title' => $todo['title'],
            'completed' => $completed,
        ];
    }

    /**
     * DELETE /todos/{id} - Todo削除
     *
     * @param int $id
     * @return array
     */
    public function delete(int $id): array
    {
        $logger = TelemetryInitializer::getLogger();
        $logger->info('Todo削除リクエスト', ['id' => $id]);

        $todo = $this->repository->findById($id);
        if ($todo === null) {
            $logger->warning('Todo が見つかりません', ['id' => $id]);
            http_response_code(404);
            return [
                'error' => 'Todo not found',
                'id' => $id,
            ];
        }

        $this->repository->delete($id);

        $logger->info('Todo削除完了', ['id' => $id]);

        return [
            'message' => 'Todo deleted',
            'id' => $id,
        ];
    }

    /**
     * ダミー外部サービス呼び出し（Context Propagation デモ用）
     *
     * 実際にはHTTPリクエストを送らないが、W3C Trace Contextヘッダーの生成方法を示す。
     * マイクロサービス間でTrace IDを伝播させる仕組み。
     *
     * @param string $title
     */
    private function notifyExternalService(string $title): void
    {
        $tracer = TelemetryInitializer::getTracer();
        $logger = TelemetryInitializer::getLogger();

        // Child Span作成（SpanKind::CLIENT = 外部サービス呼び出し）
        $span = $tracer->spanBuilder('HTTP POST notification-service')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();

        $scope = $span->activate();

        try {
            // W3C Trace Contextヘッダーを生成
            // 本来はHTTPクライアント（Guzzle等）がこれを自動でやる
            $context = \OpenTelemetry\API\Trace\Span::getCurrent()->getContext();
            $traceParent = sprintf(
                '00-%s-%s-%s',
                $context->getTraceId(),
                $context->getSpanId(),
                $context->isSampled() ? '01' : '00'
            );

            // デモ用ログ出力（実際にはHTTPリクエストを送る）
            $logger->info('外部サービスに通知を送信（ダミー）', [
                'service' => 'notification-service',
                'title' => $title,
                'traceparent' => $traceParent,  // ★ これがマイクロサービス間でTrace IDを伝播させるヘッダー
            ]);

            // Span属性を記録
            $span->setAttribute('http.method', 'POST');
            $span->setAttribute('http.url', 'http://notification-service/notify');
            $span->setAttribute('notification.title', $title);

            // 実際にはここでHTTPリクエストを送る:
            // $client = new GuzzleHttp\Client();
            // $client->post('http://notification-service/notify', [
            //     'headers' => ['traceparent' => $traceParent],
            //     'json' => ['title' => $title],
            // ]);

        } finally {
            $span->end();
            $scope->detach();
        }
    }
}

<?php

/**
 * simple-php-apm エントリーポイント
 *
 * PHPerKaigi 2026デモ用 - Pure PHP Todo API
 * フレームワークを使わず、OpenTelemetry計装を手動実装
 *
 * このファイルの役割:
 * 1. Composerオートローダーの読み込み
 * 2. OpenTelemetry初期化（毎リクエスト必須）
 * 3. ルーティング（GET/POST/PUT/DELETE）
 * 4. TelemetryMiddlewareでリクエスト全体をラップ
 * 5. エラーハンドリング（404/405）
 */

// Composerオートローダー
require_once __DIR__ . '/../vendor/autoload.php';

use SimplePhpApm\Telemetry\TelemetryInitializer;
use SimplePhpApm\Middleware\TelemetryMiddleware;
use SimplePhpApm\Handler\TodoHandler;

// PHPのShared Nothingアーキテクチャ: リクエストごとに全初期化が必要
// 商用APM（Datadog/New Relic）は、PHP拡張機能でこの初期化を自動化している
TelemetryInitializer::initialize();

// リクエスト終了時にテレメトリをフラッシュ（未送信データを送る）
register_shutdown_function([TelemetryInitializer::class, 'shutdown']);

// CORSヘッダー（デモ用、本番では適切に設定）
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// プリフライトリクエスト対応
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Content-Type: JSON APIとして動作
header('Content-Type: application/json');

// HTTPメソッドとパスを取得
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// ルーティング（Pure PHPなので手動）
// 実運用ではFastRoute等のライブラリを使うが、デモなので最小限の実装
try {
    // TelemetryMiddleware でリクエスト全体をラップ
    // ここでHTTP SERVER Spanが作成され、子Spanとしてビジネスロジックが実行される
    $middleware = new TelemetryMiddleware();
    $response = $middleware->handle($method, $path, function () use ($method, $path) {
        // TodoHandlerにルーティング
        $handler = new TodoHandler();

        // パスのパース（例: /todos/123 → ['todos', '123']）
        $segments = array_filter(explode('/', trim($path, '/')));

        // ルーティングロジック
        if (count($segments) === 1 && $segments[0] === 'todos') {
            // /todos - 一覧取得 or 作成
            if ($method === 'GET') {
                return $handler->list();
            } elseif ($method === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                return $handler->create($input);
            }
        } elseif (count($segments) === 2 && $segments[0] === 'todos') {
            // /todos/{id} - 取得/更新/削除
            $id = (int) $segments[1];
            if ($method === 'GET') {
                return $handler->get($id);
            } elseif ($method === 'PUT') {
                $input = json_decode(file_get_contents('php://input'), true);
                return $handler->update($id, $input);
            } elseif ($method === 'DELETE') {
                return $handler->delete($id);
            }
        }

        // 405 Method Not Allowed
        http_response_code(405);
        return [
            'error' => 'Method not allowed',
            'method' => $method,
            'path' => $path,
        ];
    });

    // レスポンスをJSON出力
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (\Throwable $e) {
    // 500 Internal Server Error
    // 本来はSpanにエラーを記録すべきだが、Middlewareの外なので標準エラーログのみ
    error_log('Unhandled exception: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

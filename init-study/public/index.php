<?php

declare(strict_types=1);

/**
 * OpenTelemetry PHP APM デモ - エントリーポイント
 *
 * 実行方法:
 *   php public/index.php
 *
 * または Docker 環境:
 *   docker compose up -d
 *   docker compose exec app php public/index.php
 */

// SDK 初期化 (TracerProvider / MeterProvider / LoggerProvider のグローバル登録)
$providers = require __DIR__ . '/../src/bootstrap.php';

use App\LogsExample;
use App\MetricsExample;
use App\OrderService;
use App\TraceExample;

$logger = $providers['logger'];

// ================================================================
// ヘッダー表示
// ================================================================
echo str_repeat('=', 60) . PHP_EOL;
echo '  OpenTelemetry PHP APM デモ' . PHP_EOL;
echo '  PHPカンファレンス 2026 サンプル実装' . PHP_EOL;
echo str_repeat('=', 60) . PHP_EOL . PHP_EOL;

echo '設定:' . PHP_EOL;
echo '  Collector エンドポイント: ' . (getenv('OTEL_EXPORTER_OTLP_ENDPOINT') ?: 'http://localhost:4318') . PHP_EOL;
echo '  サービス名: php-otel-demo' . PHP_EOL;
echo PHP_EOL;

// ================================================================
// Demo 1: Trace - Span 作成・属性付与・例外記録
// ================================================================
echo str_repeat('-', 60) . PHP_EOL;
echo 'Demo 1: Tracing' . PHP_EOL;
echo str_repeat('-', 60) . PHP_EOL;

$traceExample = new TraceExample();

echo PHP_EOL . '[1-1] HTTP リクエスト処理のシミュレーション' . PHP_EOL;
$traceExample->simulateHttpRequest('GET', '/orders/42');
echo '  -> Jaeger UI で Span ツリーを確認できます' . PHP_EOL;

echo PHP_EOL . '[1-2] 例外キャプチャのデモ' . PHP_EOL;
$traceExample->simulateError();
echo '  -> Jaeger UI でエラー Span と例外情報を確認できます' . PHP_EOL;

// ================================================================
// Demo 2: Metrics - Counter / Histogram / Gauge
// ================================================================
echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;
echo 'Demo 2: Metrics' . PHP_EOL;
echo str_repeat('-', 60) . PHP_EOL;

$metricsExample = new MetricsExample();
$metricsExample->runAllDemos();
echo '  -> Grafana UI でメトリクスグラフを確認できます' . PHP_EOL;

// ================================================================
// Demo 3: Logs - 構造化ログ + Trace 相関
// ================================================================
echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;
echo 'Demo 3: Logs' . PHP_EOL;
echo str_repeat('-', 60) . PHP_EOL;

$logsExample = new LogsExample($logger);
$logsExample->demonstrateStructuredLogging();
$logsExample->demonstrateTraceCorrelation();
$logsExample->demonstrateExceptionLogging();
echo '  -> Grafana Loki で構造化ログを検索できます' . PHP_EOL;

// ================================================================
// Demo 4: OrderService - 3シグナル統合
// ================================================================
echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;
echo 'Demo 4: OrderService (Trace + Metrics + Logs 統合)' . PHP_EOL;
echo str_repeat('-', 60) . PHP_EOL;

$orderService = new OrderService($logger);

$sampleOrders = [
    [
        'id'      => 'order-' . substr(md5(uniqid()), 0, 8),
        'user_id' => 'user-001',
        'items'   => [
            ['sku' => 'SKU-BOOK-001',   'qty' => 2, 'price' => 1980],
            ['sku' => 'SKU-GADGET-003', 'qty' => 1, 'price' => 8800],
        ],
    ],
    [
        'id'      => 'order-' . substr(md5(uniqid()), 0, 8),
        'user_id' => 'user-002',
        'items'   => [
            ['sku' => 'SKU-FOOD-010', 'qty' => 3, 'price' => 450],
        ],
    ],
    [
        'id'      => 'order-' . substr(md5(uniqid()), 0, 8),
        'user_id' => 'user-003',
        'items'   => [
            ['sku' => 'SKU-ELEC-005', 'qty' => 1, 'price' => 52800],
            ['sku' => 'SKU-ELEC-006', 'qty' => 2, 'price' => 3200],
        ],
    ],
];

foreach ($sampleOrders as $i => $order) {
    echo PHP_EOL . sprintf('[4-%d] 注文処理: %s', $i + 1, $order['id']) . PHP_EOL;
    try {
        $result = $orderService->createOrder($order);
        echo sprintf(
            '  -> 完了: status=%s transaction_id=%s amount=%d JPY%s',
            $result['status'],
            $result['transaction_id'],
            $result['amount'],
            PHP_EOL
        );
    } catch (\Throwable $e) {
        echo '  -> 失敗: ' . $e->getMessage() . PHP_EOL;
    }
}

// ================================================================
// フッター
// ================================================================
echo PHP_EOL . str_repeat('=', 60) . PHP_EOL;
echo '  デモ完了!' . PHP_EOL;
echo str_repeat('=', 60) . PHP_EOL;
echo PHP_EOL;
echo '各シグナルの確認先:' . PHP_EOL;
echo '  Traces  -> http://localhost:16686  (Jaeger)' . PHP_EOL;
echo '  Metrics -> http://localhost:3000   (Grafana)' . PHP_EOL;
echo '  Logs    -> http://localhost:3000   (Grafana + Loki)' . PHP_EOL;
echo PHP_EOL;

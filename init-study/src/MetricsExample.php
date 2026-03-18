<?php

declare(strict_types=1);

namespace App;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\ObserverInterface;

/**
 * メトリクス デモクラス
 *
 * デモ内容:
 * - Counter   : 単調増加する値（リクエスト数・エラー数）
 * - Histogram : 分布を記録する値（レイテンシ・ペイロードサイズ）
 * - Gauge     : 任意の値を観測（非同期：キュー長・メモリ使用量）
 * - UpDownCounter: 増減する値（接続中セッション数）
 */
class MetricsExample
{
    private \OpenTelemetry\API\Metrics\MeterInterface $meter;

    // Counter: HTTP リクエスト数
    private \OpenTelemetry\API\Metrics\CounterInterface $requestCounter;

    // Counter: エラー数
    private \OpenTelemetry\API\Metrics\CounterInterface $errorCounter;

    // Histogram: リクエスト処理時間 (ms)
    private \OpenTelemetry\API\Metrics\HistogramInterface $requestDuration;

    // Histogram: 注文金額の分布
    private \OpenTelemetry\API\Metrics\HistogramInterface $orderAmount;

    // UpDownCounter: アクティブセッション数
    private \OpenTelemetry\API\Metrics\UpDownCounterInterface $activeSessions;

    public function __construct()
    {
        $this->meter = Globals::meterProvider()->getMeter(
            'demo.meter',
            '1.0.0',
            'https://opentelemetry.io/schemas/1.24.0'
        );

        $this->initInstruments();
    }

    /**
     * メトリクス計装の初期化
     * 各 Instrument を定義し、単位と説明を付与する
     */
    private function initInstruments(): void
    {
        // Counter: 単調増加のみ（リセット不可）
        $this->requestCounter = $this->meter
            ->createCounter(
                name:        'http.server.request.total',
                unit:        '{request}',
                description: 'HTTPリクエストの総数'
            );

        $this->errorCounter = $this->meter
            ->createCounter(
                name:        'http.server.error.total',
                unit:        '{error}',
                description: 'HTTPエラーレスポンスの総数'
            );

        // Histogram: レイテンシ計測（バケット境界はデフォルト使用）
        $this->requestDuration = $this->meter
            ->createHistogram(
                name:        'http.server.request.duration',
                unit:        'ms',
                description: 'HTTPリクエストの処理時間'
            );

        $this->orderAmount = $this->meter
            ->createHistogram(
                name:        'order.amount',
                unit:        'JPY',
                description: '注文金額の分布'
            );

        // UpDownCounter: 増減可能なカウンター
        $this->activeSessions = $this->meter
            ->createUpDownCounter(
                name:        'app.active_sessions',
                unit:        '{session}',
                description: 'アクティブセッション数'
            );

        // 非同期 Gauge: コールバックで現在値を観測
        // 実際のメモリ使用量を定期的に報告するユースケース
        $this->meter
            ->createObservableGauge(
                name:        'process.memory.usage',
                unit:        'By',
                description: 'PHPプロセスのメモリ使用量'
            )
            ->observe(static function (ObserverInterface $observer): void {
                $observer->observe(memory_get_usage(true), [
                    'process.pid' => getmypid(),
                ]);
            });

        // 非同期 ObservableCounter: システム CPU 時間 (シミュレート)
        $this->meter
            ->createObservableCounter(
                name:        'process.cpu.time',
                unit:        's',
                description: 'プロセスのCPU使用時間（累積）'
            )
            ->observe(static function (ObserverInterface $observer): void {
                $usage = getrusage();
                $observer->observe(
                    ($usage['ru_utime.tv_sec'] ?? 0) + ($usage['ru_utime.tv_usec'] ?? 0) / 1_000_000,
                    ['cpu.mode' => 'user']
                );
            });
    }

    /**
     * Counter デモ: リクエスト数をカウント
     *
     * 属性（ラベル）でディメンションを付与することで
     * メソッド別・パス別・ステータス別の集計が可能
     */
    public function recordRequest(string $method, string $path, int $statusCode): void
    {
        $attributes = [
            'http.request.method'      => $method,
            'http.route'               => $path,
            'http.response.status_code'=> $statusCode,
        ];

        $this->requestCounter->add(1, $attributes);

        if ($statusCode >= 500) {
            $this->errorCounter->add(1, [
                'http.request.method' => $method,
                'http.route'          => $path,
                'error.type'          => 'server_error',
            ]);
        }

        echo sprintf(
            '  [Metrics] Counter: %s %s -> %d (累計カウント+1)%s',
            $method, $path, $statusCode, PHP_EOL
        );
    }

    /**
     * Histogram デモ: レイテンシを記録
     *
     * Histogram はパーセンタイル(p50/p95/p99)や平均値の計算に使用
     */
    public function recordLatency(string $method, string $path, float $durationMs): void
    {
        $this->requestDuration->record($durationMs, [
            'http.request.method' => $method,
            'http.route'          => $path,
        ]);

        echo sprintf(
            '  [Metrics] Histogram: %s %s duration=%.1fms%s',
            $method, $path, $durationMs, PHP_EOL
        );
    }

    /**
     * Histogram デモ: 注文金額の分布を記録
     */
    public function recordOrderAmount(float $amount, string $category): void
    {
        $this->orderAmount->record($amount, [
            'order.category' => $category,
            'currency'       => 'JPY',
        ]);

        echo sprintf(
            '  [Metrics] Histogram: order amount=%.0f JPY category=%s%s',
            $amount, $category, PHP_EOL
        );
    }

    /**
     * UpDownCounter デモ: セッション数の増減
     */
    public function sessionStarted(): void
    {
        $this->activeSessions->add(1);
        echo '  [Metrics] UpDownCounter: セッション開始 (+1)' . PHP_EOL;
    }

    public function sessionEnded(): void
    {
        $this->activeSessions->add(-1);
        echo '  [Metrics] UpDownCounter: セッション終了 (-1)' . PHP_EOL;
    }

    /**
     * 複数シナリオのバッチ実行デモ
     */
    public function runAllDemos(): void
    {
        echo PHP_EOL . '--- Metrics デモ ---' . PHP_EOL;

        // Counter: 複数リクエストをシミュレート
        $scenarios = [
            ['GET',  '/orders',      200],
            ['POST', '/orders',      201],
            ['GET',  '/orders/42',   200],
            ['GET',  '/orders/999',  404],
            ['POST', '/payments',    500],
        ];

        foreach ($scenarios as [$method, $path, $status]) {
            $this->recordRequest($method, $path, $status);
        }

        // Histogram: レイテンシをシミュレート
        $latencies = [12.5, 45.2, 8.1, 120.3, 55.7, 22.0, 89.4];
        foreach ($latencies as $latency) {
            $this->recordLatency('GET', '/orders', $latency);
        }

        // Histogram: 注文金額
        $orders = [
            [3800,  'electronics'],
            [520,   'books'],
            [15000, 'electronics'],
            [1200,  'clothing'],
            [680,   'books'],
        ];
        foreach ($orders as [$amount, $category]) {
            $this->recordOrderAmount($amount, $category);
        }

        // UpDownCounter
        $this->sessionStarted();
        $this->sessionStarted();
        $this->sessionStarted();
        $this->sessionEnded();

        echo PHP_EOL;
    }
}

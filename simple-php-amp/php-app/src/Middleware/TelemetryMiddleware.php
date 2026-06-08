<?php

namespace SimplePhpApm\Middleware;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use SimplePhpApm\Telemetry\TelemetryInitializer;

/**
 * TelemetryMiddleware - HTTPリクエスト全体をSpanでラップ
 *
 * APMの最重要コンポーネント。すべてのHTTPリクエストを1つのSpanとして記録する。
 *
 * このMiddlewareが実装していること:
 * 1. HTTP SERVER Span の作成（SpanKind::SERVER）
 * 2. HTTP属性の記録（method, route, status_code）
 * 3. エラー時のSpan記録（recordException + setStatus）
 * 4. Metricsの記録（Counter + Histogram）
 * 5. W3C Trace Context の抽出（Context Propagation）
 *
 * 商用APMは、このMiddlewareを自動で挿入している。
 * （Datadog: ddtrace拡張、New Relic: newrelic拡張）
 *
 * PHPerKaigi 2026デモ用
 */
class TelemetryMiddleware
{
    /**
     * リクエストを処理してSpanでラップ
     *
     * @param string $method HTTPメソッド
     * @param string $path リクエストパス
     * @param callable $next 実際のビジネスロジック
     * @return array レスポンスデータ
     */
    public function handle(string $method, string $path, callable $next): array
    {
        // Tracer取得
        $tracer = TelemetryInitializer::getTracer();
        $meter = TelemetryInitializer::getMeter();

        // リクエスト開始時刻（レイテンシ計測用）
        $startTime = microtime(true);

        // W3C Trace Context の抽出（Context Propagation）
        // 親サービスから traceparent ヘッダーが送られてきた場合、それを引き継ぐ
        // マイクロサービス間でTrace IDが伝播する仕組み
        $context = \OpenTelemetry\API\Trace\Propagation\TraceContextPropagator::getInstance()
            ->extract($_SERVER);

        // HTTP SERVER Span の開始
        // SpanKind::SERVER = このSpanはHTTPリクエストの受信側（サーバー）
        $span = $tracer->spanBuilder("HTTP {$method} {$path}")
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setParent($context)  // 親Spanがあればそれを引き継ぐ
            ->startSpan();

        // Spanをアクティブ化（子Spanを作るときに親として使われる）
        $scope = $span->activate();

        try {
            // HTTP属性をSpanに記録
            // OpenTelemetry Semantic Conventions に従った属性名
            // https://opentelemetry.io/docs/specs/semconv/http/
            $span->setAttribute('http.request.method', $method);
            $span->setAttribute('http.route', $path);
            $span->setAttribute('url.scheme', $_SERVER['REQUEST_SCHEME'] ?? 'http');
            $span->setAttribute('url.path', $_SERVER['REQUEST_URI'] ?? $path);
            $span->setAttribute('user_agent.original', $_SERVER['HTTP_USER_AGENT'] ?? '');

            // ビジネスロジック実行（TodoHandlerなど）
            $response = $next();

            // HTTPステータスコードをSpanに記録
            $statusCode = http_response_code();
            $span->setAttribute('http.response.status_code', $statusCode);

            // ステータスコードが4xx/5xxの場合、Spanをエラーとしてマーク
            if ($statusCode >= 400) {
                $span->setStatus(StatusCode::STATUS_ERROR, "HTTP {$statusCode}");
                $span->setAttribute('error.type', self::getErrorType($statusCode));
            } else {
                $span->setStatus(StatusCode::STATUS_OK);
            }

            return $response;

        } catch (\Throwable $e) {
            // 例外をSpanに記録（APMでエラー詳細が見える）
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->setAttribute('error.type', 'exception');

            throw $e;

        } finally {
            // Span終了（durationが自動計算される）
            $span->end();
            $scope->detach();

            // レスポンスタイム計測
            $duration = (microtime(true) - $startTime) * 1000; // ms単位

            // Metrics記録
            self::recordMetrics($meter, $method, $path, http_response_code(), $duration);
        }
    }

    /**
     * Metricsを記録
     *
     * @param \OpenTelemetry\API\Metrics\MeterInterface $meter
     * @param string $method
     * @param string $path
     * @param int $statusCode
     * @param float $duration
     */
    private static function recordMetrics($meter, string $method, string $path, int $statusCode, float $duration): void
    {
        // Counter: リクエスト数（エンドポイント別・ステータス別）
        $counter = $meter->createCounter(
            'http.requests.total',
            'requests',
            'Total number of HTTP requests'
        );
        $counter->add(1, [
            'http.method' => $method,
            'http.route' => $path,
            'http.status_code' => $statusCode,
        ]);

        // Histogram: レスポンスタイムの分布
        // P50/P95/P99などのパーセンタイルが計算できる
        $histogram = $meter->createHistogram(
            'http.server.request.duration',
            'ms',
            'HTTP request duration'
        );

        $histogramAttributes = [
            'http.request.method' => $method,
            'http.response.status_code' => $statusCode,
            'http.route' => $path,
        ];
        if ($statusCode >= 400) {
            $histogramAttributes['error.type'] = self::getErrorType($statusCode);
        }

        $histogram->record($duration, $histogramAttributes);
    }

    /**
     * HTTPステータスコードからエラータイプを判定
     *
     * @param int $statusCode
     * @return string
     */
    private static function getErrorType(int $statusCode): string
    {
        return match (true) {
            $statusCode === 400 => 'validation',
            $statusCode === 404 => 'not_found',
            $statusCode === 405 => 'method_not_allowed',
            $statusCode >= 500 => 'server_error',
            default => 'client_error',
        };
    }
}

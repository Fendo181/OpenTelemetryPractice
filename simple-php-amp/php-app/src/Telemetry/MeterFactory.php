<?php

namespace SimplePhpApm\Telemetry;

use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\API\Common\Signal\Signals;

/**
 * MeterFactory - MeterProvider の生成ロジック
 *
 * Metrics（メトリクス）は数値データの時系列記録。
 * APMで「レスポンスタイムが遅くなっている」を検知するのはこれ。
 *
 * 実装するメトリクス:
 * - Counter: http.requests.total（リクエスト数、エンドポイント別）
 * - Histogram: http.request.duration（レスポンスタイムの分布）
 * - Gauge: process.memory.usage（PHPメモリ使用量）
 *
 * PrometheusがこれをスクレイプしてGrafanaで可視化する。
 *
 * PHPerKaigi 2026デモ用
 */
class MeterFactory
{
    /**
     * MeterProvider を作成
     *
     * @return MeterProviderInterface
     */
    public static function create(): MeterProviderInterface
    {
        // 1. OTLP HTTP Metric Exporter の設定
        // CollectorにHTTPでメトリクスを送信する。エンドポイントは /v1/metrics
        $exporter = new MetricExporter(
            \OpenTelemetry\Contrib\Otlp\HttpEndpointResolver::create(
                getenv('OTEL_EXPORTER_OTLP_ENDPOINT') ?: 'http://otel-collector:4318',
                Signals::METRICS
            )
        );

        // 2. PeriodicExportingMetricReader - 定期的にメトリクスをエクスポート
        // 60秒間隔でメトリクスを集約して送信する。
        // Tracesとの違い: Tracesはリクエストごと、Metricsは時間ごと。
        $reader = new ExportingReader(
            $exporter,
            \OpenTelemetry\SDK\Common\Time\ClockFactory::getDefault()
        );

        // 3. Resource Attributes - サービスのメタデータ
        // Tracesと同じservice.nameを使うことで、相関が取れる。
        $resource = ResourceInfoFactory::emptyResource()->merge(
            ResourceInfo::create(
                Attributes::create([
                    'service.name' => getenv('OTEL_SERVICE_NAME') ?: 'simple-php-apm',
                    'service.version' => getenv('OTEL_SERVICE_VERSION') ?: '1.0.0',
                    'deployment.environment' => 'phperkaigi-2026-demo',
                ])
            )
        );

        // 4. MeterProvider の生成
        // Meter（Counter/Histogram/Gaugeを作るAPI）を提供する。
        return new MeterProvider(
            null,      // ViewRegistryは使わない（デフォルト）
            $resource, // リソース属性
            \OpenTelemetry\SDK\Common\Time\ClockFactory::getDefault(),
            null,      // Exemplar Filter（デフォルト）
            [$reader]  // MetricReaderの配列
        );
    }
}

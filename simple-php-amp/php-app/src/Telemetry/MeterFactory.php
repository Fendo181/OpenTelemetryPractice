<?php

namespace SimplePhpApm\Telemetry;

use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\HttpEndpointResolver;
use OpenTelemetry\API\Signals;

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
        $baseEndpoint = getenv('OTEL_EXPORTER_OTLP_ENDPOINT') ?: 'http://otel-collector:4318';
        $endpoint  = HttpEndpointResolver::create()->resolveToString($baseEndpoint, Signals::METRICS);
        $headers = [];
        foreach (explode(',', getenv('OTEL_EXPORTER_OTLP_HEADERS') ?: '') as $pair) {
            if (str_contains($pair, '=')) {
                [$key, $value] = explode('=', $pair, 2);
                $headers[trim($key)] = trim($value);
            }
        }
        $transport = (new OtlpHttpTransportFactory())->create($endpoint, ContentTypes::PROTOBUF, $headers);
        $exporter  = new MetricExporter($transport);

        // 2. ExportingReader - 定期的にメトリクスをエクスポート
        $reader = new ExportingReader($exporter);

        // 3. Resource Attributes - サービスのメタデータ
        $resource = ResourceInfoFactory::emptyResource()->merge(
            ResourceInfo::create(
                Attributes::create([
                    'service.name' => getenv('OTEL_SERVICE_NAME') ?: 'simple-php-apm',
                    'service.version' => getenv('OTEL_SERVICE_VERSION') ?: '1.0.0',
                    'deployment.environment' => 'phperkaigi-2026-demo',
                ])
            )
        );

        // 4. MeterProvider の生成（Builder パターンで安全に構築）
        return (new MeterProviderBuilder())
            ->setResource($resource)
            ->addReader($reader)
            ->build();
    }
}

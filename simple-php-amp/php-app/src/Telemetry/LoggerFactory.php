<?php

namespace SimplePhpApm\Telemetry;

use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\LogRecordProcessor\BatchLogRecordProcessor;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\API\Common\Signal\Signals;

/**
 * LoggerFactory - LoggerProvider の生成ロジック
 *
 * Logs（ログ）は構造化されたイベント記録。
 * APMの真価は「Trace-Log相関」。エラーログから対応するトレースにジャンプできる。
 *
 * 重要な機能:
 * - Trace ID をログに自動付与（Trace-Log相関の実現）
 * - 構造化ログ（JSON形式でkey-valueペア）
 * - OTLP経由でCollectorに送信
 *
 * 商用APMは、このTrace IDを使ってログからトレース詳細に遷移できる。
 *
 * PHPerKaigi 2026デモ用
 */
class LoggerFactory
{
    /**
     * LoggerProvider を作成
     *
     * @return LoggerProviderInterface
     */
    public static function create(): LoggerProviderInterface
    {
        // 1. OTLP HTTP Logs Exporter の設定
        // CollectorにHTTPでログを送信する。エンドポイントは /v1/logs
        $exporter = new LogsExporter(
            \OpenTelemetry\Contrib\Otlp\HttpEndpointResolver::create(
                getenv('OTEL_EXPORTER_OTLP_ENDPOINT') ?: 'http://otel-collector:4318',
                Signals::LOGS
            )
        );

        // 2. BatchLogRecordProcessor - ログを溜めて効率的に送信
        // Spanと同じくバッチ処理する。ログは量が多いのでバッチ化が重要。
        $logProcessor = new BatchLogRecordProcessor(
            $exporter,
            \OpenTelemetry\SDK\Common\Time\ClockFactory::getDefault()
        );

        // 3. Resource Attributes - サービスのメタデータ
        // Traces/Metricsと同じservice.nameで統一。これが3シグナル相関の鍵。
        $resource = ResourceInfoFactory::emptyResource()->merge(
            ResourceInfo::create(
                Attributes::create([
                    'service.name' => getenv('OTEL_SERVICE_NAME') ?: 'simple-php-apm',
                    'service.version' => getenv('OTEL_SERVICE_VERSION') ?: '1.0.0',
                    'deployment.environment' => 'phperkaigi-2026-demo',
                ])
            )
        );

        // 4. LoggerProvider の生成
        // Logger（構造化ログを出力するAPI）を提供する。
        return new LoggerProvider(
            [$logProcessor],  // LogRecordProcessorの配列
            $resource         // リソース属性
        );
    }
}

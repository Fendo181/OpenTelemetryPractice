<?php

declare(strict_types=1);

namespace App\Tracing;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;

/**
 * OpenTelemetry TracerProvider の初期化クラス
 *
 * 環境変数から設定を読み込み、OTLP HTTP エクスポーターで
 * Jaeger または New Relic にスパンを送信する。
 */
class TracingSetup
{
    private static ?TracerProvider $tracerProvider = null;

    /**
     * TracerProvider を初期化し、グローバルに登録する
     */
    public static function init(): TracerProvider
    {
        if (self::$tracerProvider !== null) {
            return self::$tracerProvider;
        }

        $serviceName = getenv('OTEL_SERVICE_NAME') ?: 'my-php-app';
        $endpoint = getenv('OTEL_EXPORTER_OTLP_ENDPOINT') ?: 'http://jaeger:4318';
        $protocol = getenv('OTEL_EXPORTER_OTLP_PROTOCOL') ?: 'http/protobuf';

        // Resource Attributes の構築
        $resourceAttributes = [
            ResourceAttributes::SERVICE_NAME => $serviceName,
            'telemetry.sdk.language' => 'php',
            'service.version' => '1.0.0',
        ];

        // 環境変数 OTEL_RESOURCE_ATTRIBUTES からの追加属性を解析
        $envAttributes = getenv('OTEL_RESOURCE_ATTRIBUTES');
        if ($envAttributes !== false && $envAttributes !== '') {
            $pairs = explode(',', $envAttributes);
            foreach ($pairs as $pair) {
                $kv = explode('=', $pair, 2);
                if (count($kv) === 2) {
                    $resourceAttributes[trim($kv[0])] = trim($kv[1]);
                }
            }
        }

        $resource = ResourceInfo::create(
            Attributes::create($resourceAttributes)
        );
        $resource = ResourceInfoFactory::defaultResource()->merge($resource);

        // OTLP HTTP トランスポートの構築
        $headers = [];
        $envHeaders = getenv('OTEL_EXPORTER_OTLP_HEADERS');
        if ($envHeaders !== false && $envHeaders !== '') {
            $headerPairs = explode(',', $envHeaders);
            foreach ($headerPairs as $headerPair) {
                $kv = explode('=', $headerPair, 2);
                if (count($kv) === 2) {
                    $headers[trim($kv[0])] = trim($kv[1]);
                }
            }
        }

        $contentType = ($protocol === 'http/protobuf')
            ? 'application/x-protobuf'
            : 'application/json';

        $transportFactory = new OtlpHttpTransportFactory();
        $transport = $transportFactory->create(
            $endpoint . '/v1/traces',
            $contentType,
            $headers
        );

        $exporter = new SpanExporter($transport);

        // TracerProvider の構築
        self::$tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(
                new BatchSpanProcessor($exporter)
            )
            ->setResource($resource)
            ->build();

        // グローバル TracerProvider と Propagator を設定
        Globals::registerInitializer(function (
            \OpenTelemetry\API\Instrumentation\Configurator $configurator
        ) {
            $configurator
                ->withTracerProvider(self::$tracerProvider)
                ->withPropagator(TraceContextPropagator::getInstance());
            return $configurator;
        });

        // PHP-FPM のフラッシュ問題対策:
        // リクエスト終了時にスパンを確実にフラッシュ・送信する
        register_shutdown_function(function (): void {
            if (self::$tracerProvider !== null) {
                self::$tracerProvider->shutdown();
            }
        });

        return self::$tracerProvider;
    }

    /**
     * TracerProvider インスタンスを返す
     */
    public static function getTracerProvider(): ?TracerProvider
    {
        return self::$tracerProvider;
    }
}

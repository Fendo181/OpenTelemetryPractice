<?php

namespace SimplePhpApm\Telemetry;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;

/**
 * TelemetryInitializer - OpenTelemetry 3シグナルの初期化を集約
 *
 * PHPのShared Nothingアーキテクチャでは、リクエストごとにすべてを初期化する必要がある。
 * これがPHPでAPMを実装する際の最大の課題。
 *
 * 商用APM（Datadog、New Relic）は、PHP拡張機能（C言語実装）でこの初期化を自動化している。
 * このクラスは、その「自動化されている部分」を手動で実装している。
 *
 * PHPerKaigi 2026デモ用
 */
class TelemetryInitializer
{
    private static ?TracerProviderInterface $tracerProvider = null;
    private static ?MeterProviderInterface $meterProvider = null;
    private static ?LoggerProviderInterface $loggerProvider = null;

    /**
     * すべてのTelemetryプロバイダーを初期化
     *
     * このメソッドは毎リクエスト呼び出す必要がある。
     * PHPはリクエスト終了時にすべてのメモリをクリアするため、シングルトンは意味を持たない。
     *
     * 重要: このコストが商用APMとの最大の違い。PHP拡張機能ならプロセス起動時1回だけでOK。
     */
    public static function initialize(): void
    {
        // 1. Tracer Provider - 分散トレーシング（Spans）
        self::$tracerProvider = TracerFactory::create();
        Globals::registerInitialTracerProvider(self::$tracerProvider);

        // 2. Meter Provider - メトリクス（Counter/Histogram/Gauge）
        self::$meterProvider = MeterFactory::create();
        Globals::registerInitialMeterProvider(self::$meterProvider);

        // 3. Logger Provider - 構造化ログ（Trace-Log相関）
        self::$loggerProvider = LoggerFactory::create();
        Globals::registerInitialLoggerProvider(self::$loggerProvider);
    }

    /**
     * Tracerの取得
     *
     * @param string $name インストルメンテーション名（通常はクラス名やライブラリ名）
     * @return \OpenTelemetry\API\Trace\TracerInterface
     */
    public static function getTracer(string $name = 'simple-php-apm'): \OpenTelemetry\API\Trace\TracerInterface
    {
        if (self::$tracerProvider === null) {
            throw new \RuntimeException('TelemetryInitializer::initialize() を先に呼び出してください');
        }

        return self::$tracerProvider->getTracer($name, '1.0.0');
    }

    /**
     * Meterの取得
     *
     * @param string $name インストルメンテーション名
     * @return \OpenTelemetry\API\Metrics\MeterInterface
     */
    public static function getMeter(string $name = 'simple-php-apm'): \OpenTelemetry\API\Metrics\MeterInterface
    {
        if (self::$meterProvider === null) {
            throw new \RuntimeException('TelemetryInitializer::initialize() を先に呼び出してください');
        }

        return self::$meterProvider->getMeter($name, '1.0.0');
    }

    /**
     * Loggerの取得
     *
     * @param string $name ロガー名
     * @return \OpenTelemetry\API\Logs\LoggerInterface
     */
    public static function getLogger(string $name = 'simple-php-apm'): \OpenTelemetry\API\Logs\LoggerInterface
    {
        if (self::$loggerProvider === null) {
            throw new \RuntimeException('TelemetryInitializer::initialize() を先に呼び出してください');
        }

        return self::$loggerProvider->getLogger($name, '1.0.0');
    }

    /**
     * シャットダウン処理
     *
     * リクエスト終了時に未送信のテレメトリをフラッシュする。
     * register_shutdown_function() で呼び出すことを推奨。
     */
    public static function shutdown(): void
    {
        if (self::$tracerProvider !== null) {
            self::$tracerProvider->shutdown();
        }
        if (self::$meterProvider !== null) {
            self::$meterProvider->shutdown();
        }
        if (self::$loggerProvider !== null) {
            self::$loggerProvider->shutdown();
        }
    }
}

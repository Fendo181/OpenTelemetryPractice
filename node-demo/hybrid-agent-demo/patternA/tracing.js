'use strict';

// ============================================================
// patternA: OTel SDK 初期化ファイル
// このファイルは app.js より先に -r オプションでロードされる
// OTel SDK の設定はここだけに集約し、app.js には書かない
// ============================================================

const { NodeSDK } = require('@opentelemetry/sdk-node');
const { OTLPTraceExporter } = require('@opentelemetry/exporter-trace-otlp-http');
const { Resource } = require('@opentelemetry/resources');
const { ATTR_SERVICE_NAME } = require('@opentelemetry/semantic-conventions');

// New Relic の OTLP エンドポイントへ直接送信する Exporter
const traceExporter = new OTLPTraceExporter({
  // New Relic US OTLP エンドポイント（EU の場合は otlp.eu01.nr-data.net）
  url: 'https://otlp.nr-data.net/v1/traces',
  headers: {
    // New Relic への認証ヘッダー（ライセンスキーを使用）
    'api-key': process.env.NEW_RELIC_LICENSE_KEY,
  },
});

// OTel SDK を初期化してトレース収集を開始する
const sdk = new NodeSDK({
  // サービス名は環境変数から取得（未設定時はデフォルト値）
  resource: new Resource({
    [ATTR_SERVICE_NAME]: process.env.OTEL_SERVICE_NAME || 'demo-pattern-a',
  }),
  traceExporter,
});

sdk.start();

console.log('[tracing] OTel SDK started. Service:', process.env.OTEL_SERVICE_NAME || 'demo-pattern-a');

// プロセス終了時に SDK をシャットダウンして未送信のスパンをフラッシュする
process.on('SIGTERM', () => {
  sdk.shutdown()
    .then(() => console.log('[tracing] OTel SDK shut down successfully'))
    .catch((err) => console.error('[tracing] SDK shutdown error:', err))
    .finally(() => process.exit(0));
});

'use strict';

// ============================================================
// patternB: New Relic Agent 設定ファイル
// このファイルは app.js より先に -r オプションでロードされる
// opentelemetry.enabled: true が Hybrid Agent の核心設定
// OTel SDK はインストール・インポートしないこと（APIのみ使用）
// ============================================================

exports.config = {
  // New Relic に表示されるアプリケーション名
  app_name: [process.env.NEW_RELIC_APP_NAME || 'demo-pattern-b'],

  // New Relic ライセンスキー（環境変数から取得）
  license_key: process.env.NEW_RELIC_LICENSE_KEY,

  // Hybrid Agent の核心設定:
  // true にすることで New Relic Agent が OTel API のブリッジとして機能する
  // これにより OTel API で作成したスパンが New Relic APM に統合される
  opentelemetry: {
    enabled: true,
  },

  // W3C TraceContext を使った分散トレーシングを有効化
  distributed_tracing: {
    enabled: true,
  },

  logging: {
    // ログレベル: error / warn / info / verbose / debug / trace
    level: 'info',
  },
};

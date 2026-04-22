# New Relic Hybrid Agent DEMO (Node.js + Docker)

2つのパターンを Docker Compose で切り替えて比較できる New Relic Hybrid Agent の DEMO アプリです。

| パターン | 技術スタック | ポート |
| --- | --- | --- |
| **OtelOnly: 純粋 OTel** | OTel SDK + OTLP 直送 | 3001 |
| **Hybrid: Hybrid Agent** | New Relic Agent + OTel API | 3002 |

---

## ディレクトリ構成

```text
hybrid-agent-demo/
├── docker-compose.otel-only.yml  # OtelOnly起動用
├── docker-compose.hybrid.yml     # Hybrid起動用
├── .env.example                  # 環境変数テンプレート
├── loadgen/                      # 負荷生成コンテナ
│   ├── Dockerfile
│   └── loadgen.js
├── otel-only/                    # 純粋 OTel パターン
│   ├── Dockerfile
│   ├── package.json
│   ├── tracing.js                # OTel SDK 初期化（SDKはここだけ）
│   └── app.js
└── hybrid/                       # Hybrid Agent パターン
    ├── Dockerfile
    ├── package.json
    ├── newrelic.js               # New Relic Agent 設定
    └── app.js                    # otel-only/app.js と同一のコード
```

---

## 1. セットアップ

```bash
cp .env.example .env
# .env を編集して NEW_RELIC_LICENSE_KEY を設定する
```

`.env` ファイルの内容:

```env
NEW_RELIC_LICENSE_KEY=your_actual_license_key_here
```

> ライセンスキーは [New Relic One](https://one.newrelic.com/) > Account settings > API keys から取得できます。

---

## 2. 起動コマンド

```bash
# OtelOnly（純粋 OTel）を起動
docker compose -f docker-compose.otel-only.yml up --build

# Hybrid（Hybrid Agent）を起動
docker compose -f docker-compose.hybrid.yml up --build
```

---

## 3. 動作確認（ローカル）

```bash
# OtelOnly の動作確認
curl http://localhost:3001/
curl http://localhost:3001/order
curl http://localhost:3001/error

# Hybrid の動作確認
curl http://localhost:3002/
curl http://localhost:3002/order
curl http://localhost:3002/error
```

---

## 4. New Relic UI での確認ポイント

| 確認箇所 | OtelOnly（純粋 OTel） | Hybrid（Hybrid Agent） |
| --- | --- | --- |
| エンティティタイプ | OpenTelemetry Service | APM Service |
| APM Summary ページ | 表示されない | 完全表示 |
| Transactions 一覧 | なし | あり（/order 等が見える） |
| Distributed Tracing | あり | あり（より詳細） |
| カスタム Span | あり | あり（NR Agent に統合） |
| Error rate | なし | あり |
| Apdex | なし | あり |

---

## 5. コードの差分ハイライト

`otel-only/app.js` と `hybrid/app.js` は **完全に同一のコード** です。  
2つのパターンの違いは初期化ファイルのみです。

```diff
# OtelOnly の起動コマンド
- CMD ["node", "-r", "./tracing.js", "app.js"]

# Hybrid の起動コマンド
+ CMD ["node", "-r", "newrelic", "app.js"]
```

```diff
# OtelOnly: tracing.js（OTel SDK を直接初期化）
- const { NodeSDK } = require('@opentelemetry/sdk-node');
- const { OTLPTraceExporter } = require('@opentelemetry/exporter-trace-otlp-http');
- const sdk = new NodeSDK({ traceExporter, resource });
- sdk.start();

# Hybrid: newrelic.js（NR Agent が OTel API をブリッジ）
+ exports.config = {
+   opentelemetry: { enabled: true },  // ← これが Hybrid Agent の核心設定
+   distributed_tracing: { enabled: true },
+ };
```

`app.js` のスパン操作コードは両パターンで完全に同一のため、  
**一度書いた OTel API コードを変更せずに New Relic APM の全機能を活用できる**ことが確認できます。

---

## 6. アーキテクチャ概要

```text
OtelOnly（純粋 OTel）
┌─────────────────────────────────────────────┐
│  app.js                                     │
│  @opentelemetry/api でスパン作成            │
│       ↓                                     │
│  tracing.js (OTel SDK)                      │
│  OTLPTraceExporter                          │
│       ↓                                     │
│  https://otlp.nr-data.net/v1/traces         │
│       ↓                                     │
│  New Relic（OTel Service として表示）       │
└─────────────────────────────────────────────┘

Hybrid（Hybrid Agent）
┌─────────────────────────────────────────────┐
│  app.js（otel-only と同一コード）           │
│  @opentelemetry/api でスパン作成            │
│       ↓                                     │
│  newrelic.js (NR Agent + OTel Bridge)       │
│  opentelemetry.enabled: true               │
│       ↓                                     │
│  New Relic Agent が自動でデータ送信         │
│       ↓                                     │
│  New Relic（APM Service として表示）        │
└─────────────────────────────────────────────┘
```

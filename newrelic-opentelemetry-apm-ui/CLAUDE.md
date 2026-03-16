# CLAUDE.md — OpenTelemetry PHP × New Relic ローカル検証プロジェクト

このファイルはClaude Codeがこのプロジェクトを操作する際の
ルール・コンテキスト・ベストプラクティスを定義します。

---

## 🗂 プロジェクト概要

| 項目           | 内容                                                                               |
| -------------- | ---------------------------------------------------------------------------------- |
| 目的           | OpenTelemetry PHP SDK を使ってローカルでトレースを収集し、New Relic APM UIで可視化 |
| 言語           | PHP 8.2                                                                            |
| フレームワーク | Slim 4（軽量・OTel自動計装対応）                                                   |
| バックエンド   | New Relic OTLP Endpoint (`https://otlp.nr-data.net`)                               |
| ローカル確認用 | Jaeger（OTLPコレクター兼UIとして使用）                                             |
| インフラ       | Docker Compose                                                                     |

---

## 📁 想定ディレクトリ構成

```
otel-php-newrelic/
├── CLAUDE.md                  ← このファイル
├── docker-compose.yml         ← PHP-FPM + Nginx + Jaeger
├── Dockerfile                 ← PHP 8.2 + OTel拡張
├── .env.example               ← 環境変数テンプレート（シークレットなし）
├── .env                       ← 実際の環境変数（gitignore対象）
├── .gitignore
├── composer.json
├── composer.lock
├── public/
│   └── index.php              ← エントリーポイント
├── src/
│   ├── Tracing/
│   │   └── TracingSetup.php   ← TracerProvider 初期化
│   └── Routes/
│       └── api.php            ← Slim ルート定義
├── nginx/
│   └── default.conf
└── docs/
    └── verification-steps.md  ← 疎通確認手順
```

---

## ⚙️ Claude Codeへの行動ルール

### コード生成

- **PHP標準に従うこと**: PSR-4オートロード、PSR-12コーディングスタイルを遵守する
- **型宣言を必ず付けること**: `declare(strict_types=1)` をすべてのPHPファイルの冒頭に記述
- **シークレットをコードに書かない**: APIキー・ライセンスキーは必ず `.env` から読み込む
- **既存ファイルを壊さない**: ファイルを編集する前に内容を確認してから変更する

### Docker / インフラ

- **PHP拡張のインストール**: `pecl install opentelemetry` を Dockerfile 内で実行し、`php.ini` で `extension=opentelemetry.so` を有効化すること
- **ヘルスチェック**: 各サービスに `healthcheck` を設定し、依存関係の起動順を保証すること
- **ポートの衝突を避けること**: デフォルトポートは以下を使用
  - Nginx: `8080`
  - Jaeger UI: `16686`
  - Jaeger OTLP(gRPC): `4317`
  - Jaeger OTLP(HTTP): `4318`

### OTel固有のルール

- **PHP-FPMのフラッシュ問題を必ず対処すること**:
  `register_shutdown_function` または `$tracerProvider->shutdown()` を使って
  リクエスト終了時にスパンが確実に送信されるようにする
- **エクスポーター設定**: ローカル確認はJaeger向け（HTTP/OTLP）、
  New Relic向けは環境変数 `OTEL_EXPORTER_OTLP_ENDPOINT` で切り替え可能にする
- **`telemetry.sdk.language=php` を必ずResource Attributesに含めること**:
  New Relic PHP APM UIを有効化するために必須

### テスト・確認

- 疎通確認用のシェルスクリプト `scripts/test-trace.sh` を作成し、
  `curl` でエンドポイントを叩いてスパンが送信されることを確認できるようにする
- Jaeger UIのURLとNew Relic APM UIの確認ポイントを `docs/verification-steps.md` にまとめること

---

## 🚫 やってはいけないこと

| NG行為                                                                  | 理由                                        |
| ----------------------------------------------------------------------- | ------------------------------------------- |
| `.env` をコミットする                                                   | シークレット漏洩のリスク                    |
| `$span->end()` を呼び忘れる                                             | スパンが不完全になりJaegerに表示されない    |
| `shutdown()` を省略する                                                 | PHP-FPMでスパンがバッファに残り送信されない |
| `open-telemetry/sdk` と `open-telemetry/api` のバージョンを不一致にする | 実行時エラーの原因                          |
| Dockerfileで `apt-get` 後に `rm -rf /var/lib/apt/lists/*` を省略する    | イメージサイズが無駄に増大                  |

---

## 🔑 環境変数の説明

```bash
# ローカル（Jaeger）向け
OTEL_SERVICE_NAME=my-php-app
OTEL_EXPORTER_OTLP_ENDPOINT=http://jaeger:4318    # Docker network内
OTEL_PHP_AUTOLOAD_ENABLED=true
OTEL_RESOURCE_ATTRIBUTES=telemetry.sdk.language=php,service.version=1.0.0

# New Relic向け（切り替え時に変更）
# OTEL_EXPORTER_OTLP_ENDPOINT=https://otlp.nr-data.net
# OTEL_EXPORTER_OTLP_HEADERS=api-key=YOUR_LICENSE_KEY
```

---

## 📦 使用するComposerパッケージ（バージョン固定推奨）

```json
{
  "require": {
    "php": ">=8.2",
    "slim/slim": "^4.0",
    "slim/psr7": "^1.6",
    "open-telemetry/sdk": "^1.0",
    "open-telemetry/exporter-otlp": "^1.0",
    "open-telemetry/opentelemetry-auto-slim": "^0.0.2",
    "php-http/guzzle7-adapter": "^1.0",
    "open-telemetry/transport-grpc": "^1.0"
  }
}
```

---

## ✅ 完了条件チェックリスト

Claude Codeは以下がすべて満たされた時点で実装完了とみなすこと:

- [ ] `docker-compose up` 一発で環境が起動する
- [ ] `curl http://localhost:8080/api/hello` でHTTP 200が返る
- [ ] Jaeger UI (`http://localhost:16686`) でサービス `my-php-app` のスパンが確認できる
- [ ] `.env.example` に必要なすべての環境変数が記載されている
- [ ] `docs/verification-steps.md` にJaeger→New Relic切り替え手順が記載されている
- [ ] `scripts/test-trace.sh` でスパン送信の疎通確認ができる

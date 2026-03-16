# Claude Code 実装プロンプト

# OpenTelemetry PHP × New Relic ローカル検証環境

---

## 🎯 タスク概要

OpenTelemetry PHP SDKを使ったローカル検証環境を**フルスクラッチ**で構築してください。
`docker-compose up` 一発で起動し、Jaeger UIでスパンが可視化でき、
設定を変えるだけでNew Relic APM UIにも送信できる環境を作ってください。

CLAUDE.mdのルールをすべて遵守してください。

---

## 📋 作成するファイル一覧

以下のファイルをすべて作成してください（順序通りに実装）:

### Phase 1: インフラ構成

**1. `Dockerfile`**

- ベースイメージ: `php:8.2-fpm`
- 以下をインストール:
  - `pecl install opentelemetry` で OTel PHP拡張をインストール
  - `extension=opentelemetry.so` を `/usr/local/etc/php/conf.d/opentelemetry.ini` に追記
  - Composer をインストール
  - `git`, `unzip`, `libzip-dev` など必要なシステム依存

**2. `docker-compose.yml`**

- サービス構成:
  ```
  php:    Dockerfileからビルド。./をマウント。depends_on: jaeger
  nginx:  nginx:alpine。ポート8080:80。php-fpmに転送
  jaeger: jaegertracing/all-in-one:latest
          ポート: 16686(UI), 4317(gRPC/OTLP), 4318(HTTP/OTLP)
  ```
- 全サービスに `healthcheck` を設定
- `env_file: .env` で環境変数を読み込む

**3. `nginx/default.conf`**

- `fastcgi_pass php:9000` でphp-fpmに転送
- `root /var/www/html/public`
- `try_files $uri /index.php$is_args$args` 設定

**4. `.env.example`**

```bash
OTEL_SERVICE_NAME=my-php-app
OTEL_EXPORTER_OTLP_ENDPOINT=http://jaeger:4318
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_PHP_AUTOLOAD_ENABLED=true
OTEL_TRACES_EXPORTER=otlp
OTEL_RESOURCE_ATTRIBUTES=telemetry.sdk.language=php,service.version=1.0.0,deployment.environment=local
# New Relic用（切り替え時にコメントアウトを外す）
# OTEL_EXPORTER_OTLP_ENDPOINT=https://otlp.nr-data.net
# OTEL_EXPORTER_OTLP_HEADERS=api-key=YOUR_NR_LICENSE_KEY
```

**5. `.gitignore`**

```
.env
vendor/
.DS_Store
*.log
```

---

### Phase 2: PHP アプリケーション

**6. `composer.json`**

以下のパッケージを含めてください:

```json
{
  "name": "otel-php-newrelic/demo",
  "require": {
    "php": ">=8.2",
    "slim/slim": "^4.0",
    "slim/psr7": "^1.6",
    "open-telemetry/sdk": "^1.0",
    "open-telemetry/exporter-otlp": "^1.0",
    "open-telemetry/opentelemetry-auto-slim": "*",
    "php-http/guzzle7-adapter": "^1.0",
    "guzzlehttp/guzzle": "^7.0"
  },
  "autoload": {
    "psr-4": {
      "App\\": "src/"
    }
  }
}
```

**7. `src/Tracing/TracingSetup.php`**

以下の仕様でTracerProviderを手動初期化するクラスを作成:

```php
<?php
declare(strict_types=1);

namespace App\Tracing;

// 役割:
// - 環境変数からOTLP endpoint, service name, resource attributesを読み込む
// - SdkTracerProviderを構築（BatchSpanProcessor + OtlpHttpExporter）
// - PHP-FPMのフラッシュ問題に対応するため
//   register_shutdown_function で $tracerProvider->shutdown() を登録
// - グローバルTracerProviderとPropagatorを設定
// - TracerProviderインスタンスを返す getTracerProvider() メソッドを持つ
```

実装のポイント:

- `OpenTelemetry\SDK\Trace\TracerProvider` を使用
- `OpenTelemetry\SDK\Common\Attribute\Attributes` でResource Attributesを設定
- `OpenTelemetry\Contrib\Otlp\OtlpHttpExporter` でエクスポート
- `OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor` を使用
- `OpenTelemetry\API\Globals` にTracerProviderを登録

**8. `public/index.php`**

```php
<?php
declare(strict_types=1);

// 役割:
// - vendor/autoload.php を require
// - TracingSetup::init() を呼び出してSDKを初期化
// - Slim 4アプリケーションを起動
// - src/Routes/api.php のルートを読み込む
```

**9. `src/Routes/api.php`**

以下の3つのエンドポイントを実装（Slim 4のルート定義）:

```
GET  /api/hello
  → "Hello from OTel PHP!" を返す
  → 手動で child span "greeting.logic" を作成してネストしたスパンを生成

GET  /api/slow
  → 0.5〜1.5秒のランダムスリープ後に応答
  → "Slow response simulated" を返す
  → span attribute に "sleep_ms" を付与

GET  /api/error
  → わざと例外をスローし、span.status を ERROR に設定して記録
  → エラーレスポンス (HTTP 500) を返す
```

各エンドポイントで `Globals::tracerProvider()->getTracer('my-php-app')` を使って
手動スパンを作成し、スパンに意味のある属性を付与してください。

---

### Phase 3: 確認用スクリプト・ドキュメント

**10. `scripts/test-trace.sh`**

```bash
#!/bin/bash
# 3つのエンドポイントを順番にcurlして、スパンが生成されることを確認するスクリプト
# 各リクエストのレスポンスとHTTPステータスを表示
# 最後に "Check Jaeger UI: http://localhost:16686" を表示
```

`chmod +x scripts/test-trace.sh` も実行してください。

**11. `docs/verification-steps.md`**

以下の内容をMarkdownで作成:

1. 環境起動手順 (`docker-compose up --build`)
2. Jaeger UIでの確認手順（スクリーンショットの代わりに操作ステップを箇条書き）
3. New Relicへの切り替え手順（`.env`の変更箇所を明示）
4. よくあるトラブルシューティング（スパンが届かない場合の確認項目）

---

## 🔧 実装後に実行すること

ファイルをすべて作成したら、以下を実行してください:

```bash
# 1. .envファイルを.env.exampleからコピー
cp .env.example .env

# 2. Dockerイメージをビルドして起動
docker-compose up --build -d

# 3. composerの依存関係をインストール（コンテナ内）
docker-compose exec php composer install

# 4. 疎通確認スクリプトを実行
bash scripts/test-trace.sh
```

---

## ✅ 成功の定義

以下をすべて確認して報告してください:

1. `docker-compose ps` で全サービスが `healthy` になっている
2. `curl -s http://localhost:8080/api/hello` で `{"message":"Hello from OTel PHP!"}` が返る
3. `http://localhost:16686` のJaeger UIで:
   - Service: `my-php-app` が存在する
   - Traces一覧に `/api/hello`, `/api/slow`, `/api/error` のスパンが表示される
   - `/api/hello` のトレースを開くと `greeting.logic` の子スパンが見える
4. エラーがある場合は原因と修正内容を報告する

---

## ⚠️ 注意事項

- `open-telemetry/opentelemetry-auto-slim` のバージョンが composer.json と競合する場合は
  `--ignore-platform-reqs` オプションや `minimum-stability: dev` で対処してください
- PHP-FPM環境では **必ず** `register_shutdown_function` でシャットダウン処理を登録してください
- スパンが届かない場合は `BatchSpanProcessor` の代わりに `SimpleSpanProcessor` を試してください
- Dockerコンテナ内のJaegerのホスト名は `jaeger`（docker-composeのサービス名）です

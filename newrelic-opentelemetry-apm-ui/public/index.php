<?php

declare(strict_types=1);

use App\Tracing\TracingSetup;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

// OpenTelemetry SDK を初期化
TracingSetup::init();

// Slim 4 アプリケーションを起動
$app = AppFactory::create();

// エラーミドルウェアを追加
$app->addErrorMiddleware(true, true, true);

// ルートを読み込む
(require __DIR__ . '/../src/Routes/api.php')($app);

// アプリケーション実行
$app->run();

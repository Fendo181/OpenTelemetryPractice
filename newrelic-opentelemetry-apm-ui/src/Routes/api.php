<?php

declare(strict_types=1);

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return function (App $app): void {
    $tracer = Globals::tracerProvider()->getTracer('my-php-app');

    /**
     * GET /api/hello
     * 挨拶メッセージを返す。子スパン greeting.logic を作成してネストしたスパンを生成。
     */
    $app->get('/api/hello', function (Request $request, Response $response) use ($tracer): Response {
        $span = $tracer->spanBuilder('GET /api/hello')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute('http.method', 'GET')
            ->setAttribute('http.route', '/api/hello')
            ->startSpan();

        $scope = $span->activate();

        try {
            // 子スパンを作成してネストしたスパンを実演
            $childSpan = $tracer->spanBuilder('greeting.logic')
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->setAttribute('greeting.type', 'hello')
                ->setAttribute('greeting.language', 'en')
                ->startSpan();

            $childScope = $childSpan->activate();

            // ビジネスロジック（簡単な処理のシミュレーション）
            $message = 'Hello from OTel PHP!';

            $childSpan->setAttribute('greeting.message', $message);
            $childSpan->end();
            $childScope->detach();

            $response->getBody()->write(json_encode(['message' => $message]));

            $span->setAttribute('http.status_code', 200);
            $span->setStatus(StatusCode::STATUS_OK);

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(200);
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $span->end();
            $scope->detach();
        }
    });

    /**
     * GET /api/slow
     * 0.5〜1.5秒のランダムスリープ後に応答。span attribute に sleep_ms を付与。
     */
    $app->get('/api/slow', function (Request $request, Response $response) use ($tracer): Response {
        $span = $tracer->spanBuilder('GET /api/slow')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute('http.method', 'GET')
            ->setAttribute('http.route', '/api/slow')
            ->startSpan();

        $scope = $span->activate();

        try {
            // 0.5〜1.5秒のランダムスリープ
            $sleepMs = random_int(500, 1500);
            usleep($sleepMs * 1000);

            $span->setAttribute('sleep_ms', $sleepMs);
            $span->setAttribute('http.status_code', 200);
            $span->setStatus(StatusCode::STATUS_OK);

            $response->getBody()->write(json_encode([
                'message' => 'Slow response simulated',
                'sleep_ms' => $sleepMs,
            ]));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(200);
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $span->end();
            $scope->detach();
        }
    });

    /**
     * GET /api/error
     * わざと例外をスローし、span.status を ERROR に設定して記録。
     */
    $app->get('/api/error', function (Request $request, Response $response) use ($tracer): Response {
        $span = $tracer->spanBuilder('GET /api/error')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute('http.method', 'GET')
            ->setAttribute('http.route', '/api/error')
            ->startSpan();

        $scope = $span->activate();

        try {
            // 意図的な例外をスロー
            $exception = new \RuntimeException('Intentional error for testing OTel error tracking');

            // エラーを記録
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
            $span->setAttribute('http.status_code', 500);
            $span->setAttribute('error.type', get_class($exception));

            $response->getBody()->write(json_encode([
                'error' => true,
                'message' => $exception->getMessage(),
            ]));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        } finally {
            $span->end();
            $scope->detach();
        }
    });
};

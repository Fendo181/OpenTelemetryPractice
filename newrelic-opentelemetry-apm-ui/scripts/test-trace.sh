#!/bin/bash
# OpenTelemetry PHP スパン疎通確認スクリプト
# 3つのエンドポイントを順番にcurlして、スパンが生成されることを確認する

set -e

BASE_URL="http://localhost:8080"

echo "============================================"
echo "  OpenTelemetry PHP トレース疎通確認"
echo "============================================"
echo ""

# 1. GET /api/hello
echo "--- [1/3] GET /api/hello ---"
HTTP_CODE=$(curl -s -o /tmp/otel-response.txt -w "%{http_code}" "${BASE_URL}/api/hello")
RESPONSE=$(cat /tmp/otel-response.txt)
echo "  HTTP Status: ${HTTP_CODE}"
echo "  Response:    ${RESPONSE}"
if [ "${HTTP_CODE}" = "200" ]; then
    echo "  Result:      ✅ OK"
else
    echo "  Result:      ❌ FAILED"
fi
echo ""

# 2. GET /api/slow
echo "--- [2/3] GET /api/slow ---"
HTTP_CODE=$(curl -s -o /tmp/otel-response.txt -w "%{http_code}" "${BASE_URL}/api/slow")
RESPONSE=$(cat /tmp/otel-response.txt)
echo "  HTTP Status: ${HTTP_CODE}"
echo "  Response:    ${RESPONSE}"
if [ "${HTTP_CODE}" = "200" ]; then
    echo "  Result:      ✅ OK"
else
    echo "  Result:      ❌ FAILED"
fi
echo ""

# 3. GET /api/error
echo "--- [3/3] GET /api/error ---"
HTTP_CODE=$(curl -s -o /tmp/otel-response.txt -w "%{http_code}" "${BASE_URL}/api/error")
RESPONSE=$(cat /tmp/otel-response.txt)
echo "  HTTP Status: ${HTTP_CODE}"
echo "  Response:    ${RESPONSE}"
if [ "${HTTP_CODE}" = "500" ]; then
    echo "  Result:      ✅ OK (期待通りの500エラー)"
else
    echo "  Result:      ❌ UNEXPECTED (期待: 500, 実際: ${HTTP_CODE})"
fi
echo ""

# クリーンアップ
rm -f /tmp/otel-response.txt

echo "============================================"
echo "  確認完了！"
echo "  Jaeger UI: http://localhost:16686"
echo "  サービス名: my-php-app を選択してください"
echo "============================================"

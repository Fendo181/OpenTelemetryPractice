# loadgen: 負荷生成コンテナ用 Dockerfile
FROM node:20-alpine

# 作業ディレクトリを設定する
WORKDIR /app

# 負荷生成スクリプトをコピーする
COPY loadgen.js ./

# 外部依存なし（Node.js 20 の fetch を使用）
CMD ["node", "loadgen.js"]

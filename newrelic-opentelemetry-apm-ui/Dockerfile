FROM php:8.2-fpm

# システム依存パッケージのインストール
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    curl \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

# OpenTelemetry PHP拡張のインストール
RUN pecl install opentelemetry \
    && echo "extension=opentelemetry.so" > /usr/local/etc/php/conf.d/opentelemetry.ini

# Composerのインストール
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# composer.jsonとcomposer.lockをコピーして依存関係をインストール
COPY composer.json ./
RUN composer install --no-dev --no-scripts --no-autoloader || true

# アプリケーションコードをコピー
COPY . .

# オートローダーの最適化
RUN composer dump-autoload --optimize || true

# PHP-FPMの設定
EXPOSE 9000

CMD ["php-fpm"]

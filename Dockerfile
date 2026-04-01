FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json ./
RUN composer install --no-interaction --prefer-dist --no-dev

FROM php:8.2-cli-alpine AS runtime
WORKDIR /app

RUN apk add --no-cache bash icu-dev libzip-dev \
    && docker-php-ext-install pdo pdo_mysql \
    && addgroup -S app && adduser -S -G app app

COPY --chown=app:app . .
COPY --from=vendor --chown=app:app /app/vendor ./vendor

USER app

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
  CMD php -r "exit(@file_get_contents('http://127.0.0.1:8000/health')!==false ? 0 : 1);"

CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]

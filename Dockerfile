# syntax=docker/dockerfile:1

##############################################
# Stage 1 - PHP dependencies (composer)
##############################################
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-progress \
    --optimize-autoloader \
    --prefer-dist \
    --ignore-platform-reqs

COPY . .

RUN composer dump-autoload --optimize --no-dev --classmap-authoritative


##############################################
# Stage 2 - Frontend assets (vite/tailwind)
##############################################
FROM node:20-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
COPY --from=vendor /app/vendor ./vendor

RUN npm run build
RUN npm prune --omit=dev


##############################################
# Stage 3 - Runtime image (php-fpm + nginx + supervisor)
##############################################
FROM php:8.3-fpm-alpine AS app

WORKDIR /var/www/html

ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium-browser \
    PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true

RUN apk add --no-cache \
        nginx \
        supervisor \
        bash \
        curl \
        git \
        unzip \
        nodejs \
        npm \
        chromium \
        nss \
        freetype \
        harfbuzz \
        ca-certificates \
        ttf-freefont \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        linux-headers \
        openssl \
        tzdata \
    && cp /usr/share/zoneinfo/Asia/Makassar /etc/localtime \
    && echo "Asia/Makassar" > /etc/timezone \
    && docker-php-ext-install \
        pdo_mysql \
        bcmath \
        pcntl \
        sockets \
        zip \
        intl \
    && rm -rf /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# App source
COPY . .

# Vendor, node_modules & compiled frontend assets from previous stages
COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend /app/node_modules ./node_modules
COPY --from=frontend /app/public/build ./public/build

# Container configuration files
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]

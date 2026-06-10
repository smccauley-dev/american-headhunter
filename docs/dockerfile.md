# Dockerfile & Container Config

## Build Strategy

One multi-stage `Dockerfile` produces one image. That same image runs on-prem (Docker Compose) and in Azure (Container Apps) without modification. The `CMD` passed at runtime determines the role: web server, queue worker, or scheduler.

---

## Dockerfile

```dockerfile
# ─────────────────────────────────────────────────────────────
# Stage 1: Node — compile frontend assets
# ─────────────────────────────────────────────────────────────
FROM node:20-alpine AS node-builder

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit

COPY resources/ resources/
COPY vite.config.js ./
COPY tailwind.config.js ./
COPY postcss.config.js ./

RUN npm run build

# ─────────────────────────────────────────────────────────────
# Stage 2: Composer — install PHP dependencies
# ─────────────────────────────────────────────────────────────
FROM composer:2 AS composer-builder

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-autoloader \
    --no-scripts \
    --prefer-dist

COPY . .
RUN composer dump-autoload --optimize --no-dev

# ─────────────────────────────────────────────────────────────
# Stage 3: Final image — PHP-FPM + Nginx
# ─────────────────────────────────────────────────────────────
FROM php:8.3-fpm-alpine AS production

# System dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    postgresql-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    curl \
    bash

# PHP extensions
RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg && \
    docker-php-ext-install \
        pdo \
        pdo_pgsql \
        pgsql \
        gd \
        zip \
        intl \
        mbstring \
        opcache \
        pcntl \
        bcmath

# phpredis — Valkey-compatible
RUN pecl install redis && docker-php-ext-enable redis

# Config files
COPY docker/php/php.ini         /usr/local/etc/php/conf.d/custom.ini
COPY docker/php/opcache.ini     /usr/local/etc/php/conf.d/opcache.ini
COPY docker/nginx/nginx.conf    /etc/nginx/nginx.conf
COPY docker/nginx/default.conf  /etc/nginx/http.d/default.conf
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

WORKDIR /var/www/html

# Copy built app
COPY --from=composer-builder /app .
COPY --from=node-builder      /app/public/build public/build/

# Permissions
RUN mkdir -p storage/logs \
             storage/framework/cache \
             storage/framework/sessions \
             storage/framework/views \
             bootstrap/cache && \
    chown -R www-data:www-data storage bootstrap/cache && \
    chmod -R 775 storage bootstrap/cache

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["web"]
```

---

## docker/entrypoint.sh

```bash
#!/bin/bash
set -e

ROLE=${1:-web}
echo "Container starting — role: $ROLE"

# Cache config on every start (fast — reads from .env)
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

case "$ROLE" in
    web)
        echo "Starting PHP-FPM + Nginx..."
        exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
        ;;

    worker)
        echo "Starting default queue worker..."
        exec php artisan queue:work valkey \
            --queue=priority,default \
            --sleep=3 \
            --tries=3 \
            --max-time=3600 \
            --memory=256
        ;;

    worker-priority)
        echo "Starting priority queue worker (SOS, payments, e-sign)..."
        exec php artisan queue:work valkey_priority \
            --queue=priority \
            --sleep=1 \
            --tries=3 \
            --max-time=3600 \
            --memory=256
        ;;

    scheduler)
        echo "Starting task scheduler..."
        while true; do
            php artisan schedule:run --verbose --no-interaction
            sleep 60
        done
        ;;

    migrate)
        echo "Running all database migrations..."
        php artisan migrate:all --force
        ;;

    *)
        exec "$@"
        ;;
esac
```

---

## docker/php/php.ini

```ini
upload_max_filesize = 64M
post_max_size       = 64M
memory_limit        = 256M
max_execution_time  = 60
expose_php          = Off
```

---

## docker/php/opcache.ini

```ini
opcache.enable                 = 1
opcache.memory_consumption     = 256
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files  = 20000
opcache.revalidate_freq        = 0
opcache.validate_timestamps    = 0
opcache.fast_shutdown          = 1
```

---

## docker/nginx/nginx.conf

```nginx
user nginx;
worker_processes auto;
error_log /var/log/nginx/error.log warn;

events {
    worker_connections 1024;
    use epoll;
}

http {
    include      /etc/nginx/mime.types;
    default_type application/octet-stream;

    sendfile       on;
    tcp_nopush     on;
    keepalive_timeout 65;
    client_max_body_size 64M;

    gzip on;
    gzip_types text/plain application/json application/javascript text/css image/svg+xml;

    include /etc/nginx/http.d/*.conf;
}
```

---

## docker/nginx/default.conf

```nginx
server {
    listen 80;
    root   /var/www/html/public;
    index  index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass           127.0.0.1:9000;
        fastcgi_param          SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include                fastcgi_params;
        fastcgi_read_timeout   60;
        fastcgi_buffer_size    16k;
        fastcgi_buffers        4 16k;
    }

    # Static assets — long cache headers
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    location ~ /\.          { deny all; }
    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }
}
```

---

## docker/supervisor/supervisord.conf

```ini
[supervisord]
nodaemon=true
logfile=/var/log/supervisor/supervisord.log
pidfile=/var/run/supervisord.pid

[program:php-fpm]
command=php-fpm -F
autostart=true
autorestart=true
priority=10
stderr_logfile=/var/log/supervisor/php-fpm.err.log
stdout_logfile=/var/log/supervisor/php-fpm.out.log

[program:nginx]
command=nginx -g "daemon off;"
autostart=true
autorestart=true
priority=20
startretries=3
stderr_logfile=/var/log/supervisor/nginx.err.log
stdout_logfile=/var/log/supervisor/nginx.out.log
```

---

## .dockerignore

```
.git
.github
.env
.env.*
node_modules
vendor
storage/logs/*
storage/framework/cache/*
storage/framework/sessions/*
storage/framework/views/*
bootstrap/cache/*
public/build
*.md
docker-compose*.yml
Makefile
tests/
```

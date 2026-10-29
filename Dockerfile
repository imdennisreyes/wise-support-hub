# ── Wise Support Hub ─────────────────────────────────────────────────────────
# PHP 8.2 + Apache, document root at public/
# ─────────────────────────────────────────────────────────────────────────────
FROM php:8.2-apache

# ── System dependencies ───────────────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libzip-dev \
        libonig-dev \
        libxml2-dev \
        zip \
        unzip \
        curl \
    && rm -rf /var/lib/apt/lists/*

# ── PHP extensions ────────────────────────────────────────────────────────────
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo \
        pdo_mysql \
        mbstring \
        gd \
        zip \
        xml \
        opcache

# ── Apache modules ────────────────────────────────────────────────────────────
RUN a2enmod rewrite headers

# ── Apache virtual host ───────────────────────────────────────────────────────
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# ── PHP production settings ───────────────────────────────────────────────────
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini

# ── Application files ─────────────────────────────────────────────────────────
WORKDIR /var/www/html
COPY . .

# ── Permissions ───────────────────────────────────────────────────────────────
# www-data needs write access to nothing in this app (no file uploads, no cache)
# but session storage uses the default /tmp which is always writable.
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type f -exec chmod 644 {} \; \
    && find /var/www/html -type d -exec chmod 755 {} \;

EXPOSE 80

FROM php:8.3-apache

# Runtime libraries needed by the ReadPilot app.
RUN apt-get update && apt-get install -y --no-install-recommends \
    libcurl4-openssl-dev \
    libonig-dev \
    libzip-dev \
    unzip \
    && docker-php-ext-install \
       curl \
       mbstring \
       pdo_mysql \
       zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Composer, used to install the locked dependency set.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

# Install the exact dependencies recorded in composer.lock. .dockerignore keeps
# the committed vendor/ directory out of the image so the container always
# resolves from the lock file rather than from whatever was on the host.
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

# Server-level web-root protection. The base image sets "AllowOverride None",
# so the project's .htaccess would otherwise be ignored inside the container.
COPY docker/apache-readpilot-security.conf /etc/apache2/conf-available/readpilot-security.conf
RUN a2enconf readpilot-security

COPY docker/entrypoint.sh /usr/local/bin/readpilot-entrypoint
RUN chmod +x /usr/local/bin/readpilot-entrypoint

# Session and upload scratch space. The web server runs unprivileged, so these
# must be writable by www-data.
RUN mkdir -p /var/www/html/uploads/profile /tmp/readpilot-private \
    && chown -R www-data:www-data /var/www/html/uploads /tmp/readpilot-private

ENV PORT=10000
EXPOSE 10000

# Binds Apache to $PORT at runtime; Render sets this per container.
ENTRYPOINT ["/usr/local/bin/readpilot-entrypoint"]
CMD ["apache2-foreground"]
FROM php:8.2-apache

# Apenas a extensão necessária (PDO MySQL) — sem libs extras supérfluas
RUN docker-php-ext-install pdo_mysql \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY php.ini /usr/local/etc/php/conf.d/99-pitstop-hardening.ini
COPY security.conf /etc/apache2/conf-available/security-pitstop.conf
RUN a2disconf security \
    && a2enconf security-pitstop \
    && a2disconf other-vhosts-access-log 2>/dev/null || true

WORKDIR /var/www/html

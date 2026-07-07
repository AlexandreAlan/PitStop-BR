FROM php:8.2-apache

# pdo_mysql (banco) + gmp (acelera a matemática de curva elíptica do Web
# Push, VAPID/ECDSA — sem ela a lib cai num fallback puro-PHP mais lento e
# loga aviso a cada notificação enviada). libgmp-dev só existe pra compilar
# a extensão; sai da imagem final, fica só o .so já linkado.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libgmp-dev \
    && docker-php-ext-install pdo_mysql gmp \
    && apt-get purge -y --auto-remove libgmp-dev \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY php.ini /usr/local/etc/php/conf.d/99-pitstop-hardening.ini
COPY security.conf /etc/apache2/conf-available/security-pitstop.conf
RUN a2disconf security \
    && a2enconf security-pitstop \
    && a2disconf other-vhosts-access-log 2>/dev/null || true

# O vhost padrão da imagem define o próprio ErrorLog dentro do bloco
# <VirtualHost>, o que sobrescreve o ErrorLog do server principal (definido
# em security.conf) pra tudo que é logado durante uma requisição — inclusive
# error_log() do PHP (é como o report-uri do CSP chega nos logs). Sem esse
# sed, os relatórios de violação iam parar só no /var/log/apache2/error.log
# (tmpfs, some ao reiniciar), nunca em `docker compose logs`.
RUN sed -i 's#ErrorLog \${APACHE_LOG_DIR}/error.log#ErrorLog /dev/stderr#' /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

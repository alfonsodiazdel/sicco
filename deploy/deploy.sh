#!/usr/bin/env bash
# Despliega (o actualiza) la demo de SICCO en el Droplet.
# Lo ejecuta el workflow "Deploy demo" por SSH, o a mano desde el Droplet:
#   bash /opt/sicco/deploy/deploy.sh
set -euo pipefail

cd "$(dirname "$0")/.."

dc() { docker compose -f docker-compose.demo.yml --env-file backend/.env "$@"; }
env_valor() { grep -E "^$1=" backend/.env | tail -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
paso() { echo; echo "==> $*"; }

# 1. Variables de entorno: la primera vez se crean desde la plantilla.
if [ ! -f backend/.env ]; then
    cp backend/.env.demo.example backend/.env
    sed -i \
        -e "s/^DB_PASSWORD=$/DB_PASSWORD=$(openssl rand -hex 24)/" \
        -e "s/^DB_ROOT_PASSWORD=$/DB_ROOT_PASSWORD=$(openssl rand -hex 24)/" \
        backend/.env
    echo "Se creó backend/.env con contraseñas de base de datos aleatorias."
    echo "Edítalo (nano $(pwd)/backend/.env), reemplaza los valores CAMBIAR y vuelve a desplegar."
    exit 1
fi

# Solo líneas de variables; los comentarios de la plantilla también mencionan CAMBIAR.
if grep -q "^[^#]*CAMBIAR" backend/.env; then
    echo "backend/.env todavía tiene valores CAMBIAR:"
    grep -n "^[^#]*CAMBIAR" backend/.env
    exit 1
fi

if [ ! -f frontend/dist/index.html ]; then
    echo "Falta frontend/dist: la SPA se compila en GitHub Actions y se copia antes de este script."
    exit 1
fi

# Carpetas de trabajo de Laravel (storage/ no se copia desde el repositorio).
mkdir -p backend/storage/app/public backend/storage/logs \
    backend/storage/framework/{cache/data,sessions,views,testing}

paso "Construyendo imagen de PHP"
dc build php

paso "Levantando MySQL, S3 y Mailpit"
dc up -d mysql s3 s3-init mailpit

paso "Instalando dependencias de Composer"
dc run --rm php composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

if [ -z "$(env_valor APP_KEY)" ]; then
    paso "Generando APP_KEY"
    dc run --rm php php artisan key:generate --force
fi

paso "Migraciones, roles y grupos de partidas"
dc run --rm php php artisan migrate --force
dc run --rm php php artisan db:seed --class=RolSeeder --force
dc run --rm php php artisan db:seed --class=GrupoPartidaSeeder --force

# Sin "optimize": la API no tiene vistas Blade y resources/views no existe en el repositorio.
paso "Cacheando configuración y rutas"
dc run --rm php sh -c "php artisan config:cache && php artisan route:cache && php artisan event:cache"

paso "Levantando PHP, cola y nginx"
dc up -d --remove-orphans
dc exec -T php php artisan queue:restart

# 2. Certificado HTTPS (solo si hay dominio configurado).
dominio="$(env_valor DEMO_DOMAIN)"
if [ -n "$dominio" ]; then
    if dc run --rm --entrypoint test certbot -f "/etc/letsencrypt/live/$dominio/fullchain.pem"; then
        paso "Renovando certificado si está por vencer"
        dc run --rm certbot renew --quiet
        dc exec -T nginx nginx -s reload
    else
        paso "Emitiendo certificado de Let's Encrypt para $dominio"
        if ! dc run --rm certbot certonly --webroot -w /var/www/certbot \
            -d "$dominio" --email "$(env_valor LETSENCRYPT_EMAIL)" \
            --agree-tos --no-eff-email --non-interactive; then
            echo "No se pudo emitir el certificado. Revisa que el registro A de $dominio apunte a este Droplet"
            echo "y que el puerto 80 esté abierto. La app sigue disponible por HTTP."
            exit 1
        fi
        dc restart nginx
    fi
fi

paso "Listo"
dc ps --format "table {{.Service}}\t{{.Status}}"

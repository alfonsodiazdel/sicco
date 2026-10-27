#!/bin/sh
# Copia la plantilla que corresponda; la imagen oficial de nginx la procesa
# con envsubst (reemplaza ${DEMO_DOMAIN}) y la deja en /etc/nginx/conf.d/.
set -eu

mkdir -p /etc/nginx/templates

if [ -n "${DEMO_DOMAIN:-}" ] && [ -f "/etc/letsencrypt/live/${DEMO_DOMAIN}/fullchain.pem" ]; then
    cp /etc/nginx/sicco/https.conf.template /etc/nginx/templates/default.conf.template
    echo "nginx: HTTPS para ${DEMO_DOMAIN}"
else
    cp /etc/nginx/sicco/http.conf.template /etc/nginx/templates/default.conf.template
    echo "nginx: solo HTTP (sin certificado todavía)"
fi

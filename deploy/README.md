# Despliegue de la demo en un Droplet

Pensado para la exposición ante el jurado: todo SICCO en un solo Droplet de DigitalOcean
con Docker, HTTPS de Let's Encrypt y despliegue con un botón desde GitHub Actions.

```
GitHub Actions (botón "Run workflow")
  ├─ CI: Pint, Pest, ESLint, type-check, Vitest
  ├─ Compila la SPA (frontend/dist)
  ├─ rsync del repositorio al Droplet  →  /opt/sicco
  └─ ssh: bash deploy/deploy.sh
        ├─ composer install, migraciones, roles, caché
        ├─ docker compose -f docker-compose.demo.yml up -d
        └─ certificado de Let's Encrypt (la primera vez) o renovación

Droplet: nginx (80/443) ─┬─ SPA        https://<dominio>/
                         ├─ API        https://<dominio>/api/v1  → php-fpm
                         └─ Mailpit    https://<dominio>/mailpit/
         php · queue · mysql · s3 (SeaweedFS) · mailpit  → solo red interna
```

| Archivo | Para qué |
|---|---|
| `docker-compose.demo.yml` | Servicios de la demo (solo nginx publica puertos; MySQL ajustado para 1–2 GB). |
| `docker/nginx/demo/` | SPA + API + Mailpit; plantilla HTTP o HTTPS según exista el certificado. |
| `backend/.env.demo.example` | Plantilla de variables del Droplet. |
| `deploy/deploy.sh` | Lo que se ejecuta en el Droplet en cada despliegue. |
| `.github/workflows/deploy.yml` | Workflow "Deploy demo" (manual). |

## 1. Crear el Droplet

- **Imagen:** Marketplace → *Docker* (Ubuntu con Docker y Compose instalados).
- **Plan:** Basic, Regular, 2 GB (US$ 12/mes, se cobra por hora). 1 GB funciona con swap.
- **Región:** New York.
- **Autenticación:** tu SSH key personal.
- **Firewall (Networking → Firewalls):** entrada solo TCP 22, 80 y 443.

## 2. Dominio

En el panel del registrador (p. ej. Name.com del Student Pack) crea un registro **A**
del dominio o subdominio (p. ej. `demo.sicco.app`) apuntando a la IP del Droplet.
Compruébalo desde tu PC con `nslookup demo.sicco.app` antes del primer despliegue.

## 3. Preparar el Droplet (una sola vez)

Entra como root (`ssh root@IP_DEL_DROPLET`):

```bash
# Usuario de despliegue (UID 1000, el mismo que www-data dentro del contenedor de PHP)
adduser --disabled-password --gecos "" sicco
usermod -aG docker sicco
id sicco

# Carpeta del proyecto
mkdir -p /opt/sicco && chown sicco:sicco /opt/sicco

# Swap de 2 GB (red de seguridad para la RAM)
fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab

apt-get update && apt-get install -y rsync
```

Si `id sicco` no muestra `uid=1000`, agrega `DEPLOY_UID=<uid>` y `DEPLOY_GID=<gid>` a
`backend/.env` en el paso 6.

## 4. Llave SSH para GitHub Actions

En tu PC (Git Bash), crea una llave solo para despliegues, sin passphrase:

```bash
ssh-keygen -t ed25519 -C "github-actions-sicco" -f ~/.ssh/sicco_deploy -N ""
```

Autoriza la llave pública en el Droplet (como root):

```bash
mkdir -p /home/sicco/.ssh
echo "CONTENIDO_DE_sicco_deploy.pub" >> /home/sicco/.ssh/authorized_keys
chown -R sicco:sicco /home/sicco/.ssh && chmod 700 /home/sicco/.ssh && chmod 600 /home/sicco/.ssh/authorized_keys
```

Prueba desde tu PC: `ssh -i ~/.ssh/sicco_deploy sicco@IP_DEL_DROPLET`.

## 5. Secrets del repositorio

GitHub → repositorio → **Settings → Secrets and variables → Actions → New repository secret**:

| Secret | Valor |
|---|---|
| `DROPLET_HOST` | IP del Droplet |
| `DROPLET_USER` | `sicco` |
| `DROPLET_SSH_KEY` | Contenido completo de `~/.ssh/sicco_deploy` (la llave **privada**) |
| `DROPLET_KNOWN_HOSTS` | Salida de `ssh-keyscan -t ed25519 IP_DEL_DROPLET` |

`DROPLET_KNOWN_HOSTS` hace que Actions verifique que se conecta a tu Droplet y no a otro.

## 6. Primer despliegue

1. GitHub → **Actions → Deploy demo → Run workflow** (rama `main`).
2. La primera vez **termina en rojo a propósito**: copia el código, crea `backend/.env`
   con contraseñas de base de datos aleatorias y se detiene para que lo revises.
3. Edita el archivo en el Droplet:
   ```bash
   ssh sicco@IP_DEL_DROPLET
   nano /opt/sicco/backend/.env
   ```
   Reemplaza todos los `CAMBIAR`: `DEMO_DOMAIN`, `LETSENCRYPT_EMAIL`, `MAILPIT_UI_AUTH`,
   `APP_URL`, `SANCTUM_STATEFUL_DOMAINS` y `FRONTEND_URL`. No cambies `DB_PASSWORD` ni
   `DB_ROOT_PASSWORD` después del primer arranque de MySQL.
4. **Run workflow** otra vez. Ahora instala, migra, levanta todo y emite el certificado.
   Al aceptar `--agree-tos` en el script aceptas los términos de Let's Encrypt.
5. Abre `https://<dominio>`.

Desde entonces, cada **Run workflow** pasa la CI y actualiza el Droplet con lo que esté en `main`.

## 7. Usuarios para la demo

En producción no se crean usuarios demo automáticamente. `UsuarioDemoProduccionSeeder`
crea uno por rol (`<rol>@sicco.app`) con la contraseña temporal de `DEMO_PASSWORD`;
al primer ingreso el sistema obliga a cambiarla (CU01).

1. En `backend/.env` del Droplet define `DEMO_PASSWORD` (mínimo 8 caracteres, letras y números).
2. Vuelve a cachear la configuración (para que tome la variable) y ejecuta el seeder:

```bash
ssh sicco@IP_DEL_DROPLET
cd /opt/sicco
alias dc='docker compose -f docker-compose.demo.yml --env-file backend/.env'
dc exec php php artisan config:cache
dc exec php php artisan db:seed --class=UsuarioDemoProduccionSeeder --force
```

Volver a ejecutarlo no cambia las contraseñas de usuarios que ya existen.

## Comandos útiles en el Droplet

```bash
cd /opt/sicco
alias dc='docker compose -f docker-compose.demo.yml --env-file backend/.env'
dc ps                              # estado de los servicios
dc logs -f nginx php               # logs
dc exec php php artisan tinker     # consola de Laravel
tail -f backend/storage/logs/laravel.log
free -h                            # memoria
```

## Notas

- **Sin dominio:** deja `DEMO_DOMAIN=` vacío, usa `http://IP` en `APP_URL`,
  `SANCTUM_STATEFUL_DOMAINS=IP` y `SESSION_SECURE_COOKIE=false`. Funciona, pero sin HTTPS.
- **Certificado:** dura 90 días; cada despliegue intenta renovarlo.
- **Archivos (S3):** SeaweedFS corre dentro del Droplet y no se expone. Cuando se
  implemente la descarga de guías (CU06), las URL firmadas necesitarán un endpoint público
  (o pasar a DigitalOcean Spaces cambiando solo las variables `AWS_*`).
- **Después de la exposición:** destruye el Droplet (Destroy) para dejar de pagar.

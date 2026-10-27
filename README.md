# SICCO

Sistema de Control de Consumo de Concreto en Obra. API REST en Laravel 13 (PHP 8.5)
y SPA en Vue 3 + TypeScript. Arquitectura y decisiones: `Docs/arquitectura.md` (carpeta del proyecto, fuera de este repositorio).

```
backend/    API Laravel (Sanctum, sesión por cookie)
frontend/   SPA Vue 3 + Vite + PrimeVue 4 + Tailwind 4
docker/     Imágenes y configuración del entorno local
```

## Requisitos

- Docker Desktop (PHP, MySQL, correo y almacenamiento S3 corren en contenedores)
- Node.js 22.18+ o 24.12+

## Primer arranque

```bash
docker compose up -d --build
docker compose exec php composer install
docker compose exec php cp .env.example .env
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate --seed
npm --prefix frontend install
npm --prefix frontend run dev
```

Abrir http://localhost:5173. En local el seeder crea un usuario demo por rol
(`<rol>@sicco.test`, por ejemplo `oficina_tecnica@sicco.test`); la contraseña está
en `backend/database/seeders/UsuarioDemoSeeder.php`.

| Servicio | URL |
|---|---|
| SPA (Vite) | http://localhost:5173 |
| API (nginx) | http://localhost:8000/api/v1 |
| Correos (Mailpit) | http://localhost:8025 |
| S3 local (SeaweedFS) | http://localhost:8333 (bucket `sicco`) |
| MySQL | localhost:3306 (`sicco` / `sicco`) |

Vite reenvía `/api` y `/sanctum` a nginx, de modo que la SPA y la API comparten
origen como en producción (`sicco.app` y `sicco.app/api`).

## Comandos frecuentes

```bash
docker compose exec php ./vendor/bin/pest          # pruebas backend
docker compose exec php ./vendor/bin/pint          # formato PHP
npm --prefix frontend run test:unit -- --run       # pruebas frontend
npm --prefix frontend run lint
npm --prefix frontend run type-check
docker compose --profile queue up -d queue         # worker de colas (correos)
```

## Autenticación y roles

- `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/auth/me`,
  `PUT /api/v1/auth/password`. Antes del login la SPA pide `GET /sanctum/csrf-cookie`.
- La sesión expira tras 30 min de inactividad (`SESSION_LIFETIME=30`).
- Roles fijos del documento: `administrador`, `almacenero`, `ingeniero_produccion`,
  `oficina_tecnica`, `gerencia` (`backend/app/Enums/Rol.php` y `frontend/src/types/auth.ts`).
- Backend: `->middleware('rol:oficina_tecnica,gerencia')`. Frontend: `meta.roles` en
  la ruta y `src/router/menu.ts` para el menú.

## Despliegue de la demo

Un solo Droplet de DigitalOcean con Docker, HTTPS y despliegue manual desde GitHub
Actions (workflow "Deploy demo"). Paso a paso en [deploy/README.md](deploy/README.md).

## Almacenamiento: local vs. producción

Laravel usa el disco `s3` en ambos entornos; solo cambian las variables:

| Variable | Local (SeaweedFS) | Producción (DigitalOcean Spaces) |
|---|---|---|
| `AWS_ENDPOINT` | `http://s3:8333` | `https://nyc3.digitaloceanspaces.com` |
| `AWS_DEFAULT_REGION` | `us-east-1` | `nyc3` |
| `AWS_BUCKET` | `sicco` | nombre del Space |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `true` | `false` |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | `sicco` / `sicco-secret` | llaves de acceso de Spaces |

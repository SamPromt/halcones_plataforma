# Halcones PHP API

Modular monolith backend for the Halcones mobility prototype. The API uses Slim 4, PDO, MySQL/MariaDB and JWT. Existing HTML pages are served by the same origin; their layout and Tailwind classes remain unchanged.

## Requirements

- PHP 8.0+ with `pdo_mysql`, `openssl`, `json` and `curl` enabled.
- Composer 2.
- MySQL 8+ or MariaDB 10.4+.
- Docker Compose only when running local Soketi WebSockets.

## Local setup (PowerShell)

```powershell
Set-Location backend-php
Copy-Item .env.example .env
```

Set a private `JWT_SECRET` of at least 32 random characters and the database credentials in `.env`. Never deploy the development values. Then run:

```powershell
php C:\path\to\composer.phar install
php C:\path\to\composer.phar database:migrate
$env:HALCONES_USER_PASSWORD = 'use-a-unique-password-of-at-least-12-characters'
php bin/create-user.php 'Operator Name' '+57XXXXXXXXXX' OPERATOR
php bin/create-user.php 'Driver Name' '+57YYYYYYYYYY' DRIVER HLC-001 CAR 'Vehicle Model' ABC123
php C:\path\to\composer.phar serve
```

Open `http://127.0.0.1:8080/` for the public page, `/2_consola.html` for dispatch and `/3_conductor.html` for the driver PWA. The account creation command hashes passwords and never writes them to the database in plain text.

## WebSockets

Soketi implements the Pusher protocol and is defined in `compose.yml`:

```powershell
docker compose -f compose.yml up -d
```

Set `PUSHER_ENABLED=true` in `.env`, keeping the local key/secret consistent with the compose service. Run the publisher in a separate terminal:

```powershell
php bin/outbox-worker.php --watch
```

The outbox is written in the same database transaction as each service, driver or payment change. The worker publishes confirmed events and retries failures with backoff. When no Pusher-compatible service is running, clients continue to refresh through the HTTP API.

## WhatsApp Meta setup

Configure `META_VERIFY_TOKEN`, `META_APP_SECRET`, `META_ACCESS_TOKEN`, `META_PHONE_NUMBER_ID`, `META_GRAPH_API_VERSION`, and `WHATSAPP_OPERATOR_PHONE` in `.env`. Set Meta's webhook URL to `https://your-domain/api/v1/webhooks/whatsapp` and subscribe to message events. Meta requires a publicly reachable HTTPS URL; use a tunnel only for development. The website keeps its existing `wa.me` links and updates their destination from `WHATSAPP_OPERATOR_PHONE` when configured.

The bot asks for an origin and then a destination. Message IDs are unique in `webhook_messages`, so Meta retries cannot create duplicate rides. Real credentials and Meta webhook verification have not been supplied in this local environment.

## API overview

- `POST /api/v1/auth/login`, `POST /api/v1/auth/refresh`, `POST /api/v1/auth/logout`, `GET /api/v1/auth/me`
- `PATCH /api/v1/drivers/me/status`, `POST /api/v1/drivers/me/location`, `GET /api/v1/drivers/available`
- `POST /api/v1/services`, `GET /api/v1/services`, `GET /api/v1/services/{id}`
- `POST /api/v1/services/{id}/assign`, `POST /api/v1/services/{id}/assign/auto`
- `POST /api/v1/services/{id}/actions/{accept|reject|en-route|arrive|start|complete|cancel}`
- `POST /api/v1/services/{id}/payments`, `GET /api/v1/reports/financial`
- `POST /api/v1/broadcasting/auth`
- `GET|POST /api/v1/webhooks/whatsapp`
- `GET /api/v1/dashboard/summary`, `GET /api/v1/health`, `GET /api/v1/public/config`

Mutating requests are role-protected. Payments require an `Idempotency-Key`. Service assignment locks service and driver rows and also relies on the unique `current_driver_assignments` table to prevent double assignment.

## Tests

```powershell
php C:\path\to\composer.phar test
```

The current test suite checks JWT issuance, claims and invalid-token rejection. The local HTTP smoke test additionally exercised login/refresh/revocation and the full service/payment lifecycle against MariaDB.

## Deployment notes

The PHP built-in server is development-only. In production serve `public/` through Apache/PHP-FPM behind HTTPS. Use a dedicated database account with least privilege, rotate all secrets, restrict CORS to the production origin, keep Soketi private behind TLS, back up MySQL, and configure scheduled retention for old GPS points and published outbox rows. Access and refresh tokens are currently kept in browser storage for the static prototype; production should move refresh tokens to Secure, HttpOnly, SameSite cookies.

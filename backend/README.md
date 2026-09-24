# PTC Hub Backend

Simple Laravel REST API for the PTC Hub frontend.

## Stack

- Laravel 13
- MySQL in production (per `.env.example`), SQLite locally; PostgreSQL is also supported
- Laravel Sanctum
- External content links by default; optional S3/Cloudflare R2 presigned uploads are available in the backend
- Database queue by default; Redis can be enabled later

## Run locally

The full delivery uses SQLite locally so no database server is required:

```bash
composer install
cp .env.local.example .env.local
php -r "touch('database/database.sqlite');"
php artisan key:generate --env=local
php artisan migrate --seed --env=local
php artisan db:seed --class=ToolDirectorySeeder --env=local
```

To run the frontend and API together, use the project-root command documented in the delivery guide:

```bash
php -S localhost:8000 -t ptc-hub-frontend dev-server.php
```

API base URL through the integrated frontend gateway:

```text
http://localhost:8000/index.php/api/v1
```

## Create the first admin

Set these values in `.env` before seeding:

```env
ADMIN_NAME="Osama Ayesh"
ADMIN_EMAIL="admin@example.com"
ADMIN_PASSWORD="strong-password"
```

Then run:

```bash
php artisan db:seed --class=AdminSeeder
```

## File storage

The delivered frontend uses external content URLs and does not upload file bytes to this application. The database stores only file metadata/URLs.

The backend also contains an **optional** presigned-upload endpoint (`POST /staff/uploads/presign`). It is not wired into the current frontend. Enable it only if you intentionally configure S3/Cloudflare R2 and add the corresponding frontend upload flow.

For Cloudflare R2:

```env
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=auto
AWS_BUCKET=ptc-hub
AWS_ENDPOINT=https://ACCOUNT_ID.r2.cloudflarestorage.com
AWS_USE_PATH_STYLE_ENDPOINT=false
```

Optional large-file flow (after wiring it into the frontend):

1. Frontend requests `POST /staff/uploads/presign`.
2. Frontend uploads directly to S3/R2 using the returned URL and headers.
3. Frontend saves file metadata with `POST /staff/course-files`.

## Main folders

```text
app/Http/Controllers/Api/V1   API controllers
app/Models                    Database models
app/Http/Middleware           Role middleware
database/migrations           Database tables
database/seeders              Courses and admin seeders
routes/api.php                All API endpoints
docs                          Frontend and API notes
```

## Tests

```bash
php artisan test
```

## Important

- Do not upload `.env` to GitHub.
- The frontend should send the token as `Authorization: Bearer TOKEN`.
- Use a unique course `key`, not only `code`. Elective courses share the same displayed code.

## Bucket CORS

Allow the frontend domain to send `PUT` and `GET` requests to the S3/R2 bucket. Allow at least the `Content-Type` header. Without bucket CORS, direct browser uploads will be blocked.

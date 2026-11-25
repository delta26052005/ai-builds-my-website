# AI Builds My Website

AI Builds My Website turns a business owner's project details and confirmed business facts into an editable, single-page website. The monorepo contains a Laravel REST API and a separate React/Vite frontend.

## Requirements

- PHP 8.3 and Composer
- MySQL 8+
- Node.js and npm
- A Tavily API key for web research and a Gemini API key for fact extraction and website generation (optional for local development)

## Local setup

### 1. Configure the Laravel API

Create a MySQL database, then from `backend/`:

```powershell
Copy-Item .env.example .env
composer install
php artisan key:generate
```

Set `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `backend/.env` to match your local MySQL account. Add provider credentials only to this server-side file when you want to use research and AI features:

```dotenv
TAVILY_API_KEY=
GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.5-flash
```

Do not put provider keys in frontend environment variables. `.env` is local and must not be committed.

Apply the schema and start the API and database queue worker in separate terminals:

```powershell
php artisan migrate
php artisan serve --host=localhost --port=8000
```

```powershell
php artisan queue:work database --timeout=60
```

The queue worker is required for source extraction, business research, fact extraction, and website generation.

### 2. Start the React frontend

From `frontend/`:

```powershell
npm install
npm run dev -- --host localhost --port 5174
```

Open the Vite URL in a browser. The frontend defaults to the API at `http://localhost:8000/api`; set `VITE_API_BASE_URL` only if your Laravel API uses another address. Laravel CORS defaults permit `http://localhost:5174` and `http://127.0.0.1:5174`.

## Application workflow

1. Register and create a business project.
2. Add project documents, images, website/social links, and available business details.
3. Run research and fact extraction. Accessible research and uploaded source content are treated as untrusted; inaccessible sources are not represented as retrieved content.
4. Review evidence and explicitly confirm facts. Only confirmed facts are used as factual inputs to website generation.
5. Generate a website using `modern_business` or `local_business`, edit its structured content, and save revisions.
6. Publish from the Website Builder. The public URL is `/sites/{slug}` on the frontend origin. Publishing does not create a new generated version; unpublishing immediately makes the public page and its gallery image URLs return 404.

Public website reads do not require a login and expose only the current published website content. Uploaded files remain private. An uploaded image is served publicly only when its source ID is included in the currently published website gallery; PDFs and other project sources are never exposed by the public image endpoint. Public responses are sent with `Cache-Control: no-store`.

The website is rendered from a fixed JSON schema through the two predefined React templates. Generated HTML and JavaScript are never executed. AI-generated copy is identified as unverified, and owners should review it before publishing.

## Useful commands

Run backend commands from `backend/` and frontend commands from `frontend/`.

```powershell
php artisan test
vendor/bin/pint --test
npm run lint
npm run build
```

The PHP commands are run from `backend/`; the npm commands are run from `frontend/`.

## API overview

All API paths are under `/api`. Authentication uses Laravel Sanctum SPA sessions. Project and website management endpoints require an authenticated project owner. Public read endpoints are intentionally unauthenticated:

```text
GET    /api/public/websites/{slug}
GET    /api/public/websites/{slug}/images/{source}

POST   /api/projects/{project}/website/publish
DELETE /api/projects/{project}/website/publish
```

See [backend/README.md](./backend/README.md) for backend endpoints, queue behavior, and data-safety details.

# AI Builds My Website frontend

## Run locally

Use the same hostname for the frontend and API. The example configuration uses
`localhost` for both:

```powershell
# Terminal 1, from the monorepo root
cd backend
php artisan migrate
php artisan serve --host=localhost --port=8000

# Terminal 2, from the monorepo root
cd frontend
Copy-Item .env.example .env
npm install
npm run dev
```

The Vite server runs at `http://localhost:5174`. The frontend API URL is set by
`VITE_API_BASE_URL` in the frontend `.env` file. This value is public and must
only contain the API base URL; never put credentials or secrets in a `VITE_`
variable.

To use `127.0.0.1` instead, set
`VITE_API_BASE_URL=http://127.0.0.1:8000/api` and open the frontend at
`http://127.0.0.1:5174`.

Authentication uses Laravel Sanctum session cookies. The frontend obtains a
CSRF cookie before login or registration and sends requests with credentials;
it does not store authentication tokens in browser storage.

## Production configuration

Use HTTPS for both applications. Configure the backend's
`CORS_ALLOWED_ORIGINS` and `SANCTUM_STATEFUL_DOMAINS` with the exact deployed
frontend origin and hostname. When frontend and API use subdomains, set
`SESSION_DOMAIN` to their shared parent domain. Production session cookies are
Secure and HttpOnly by default.

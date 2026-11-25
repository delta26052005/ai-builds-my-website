<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Business intelligence engine

The project details page can run public Tavily research and Gemini-backed fact extraction. Configure these values in the backend environment; the API keys are only used by Laravel and are never sent to the browser or included in model prompts:

```dotenv
TAVILY_API_KEY=
GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.5-flash
QUEUE_CONNECTION=database
```

Apply migrations and run a database queue worker from the `backend` directory:

```powershell
php artisan migrate
php artisan queue:work database --timeout=60
```

Research uses Tavily's public search results and does not directly fetch user-provided URLs. Supplied links remain marked inaccessible unless public Tavily content is returned for them. Uploaded documents and search results are treated as untrusted text; extracted facts remain unverified until the project owner confirms them. OCR and image understanding are not included.

## Website builder

The website builder uses only owner-confirmed Phase 5 fact candidates as factual input. Laravel sends bounded confirmed values and evidence to the existing Gemini integration, validates its JSON against a fixed schema, and stores the result in `generated_websites`. Owner edits and successful section regenerations create immutable `website_revisions`; failed jobs leave the last saved content intact. React renders the saved JSON through the predefined `modern_business` and `local_business` templates. Generated HTML, JavaScript, and React source are never executed.

The content schema has the website keys `business_name`, `tagline`, `template_key`, `theme`, `navigation`, `hero`, `about`, `services_or_products`, `highlights`, `gallery`, `contact`, and `footer`, plus the backend-managed `content_provenance` map. Unknown keys are rejected. The backend enforces this field contract:

| Field | Type and limits |
| --- | --- |
| `business_name` | Required string, max 160 characters; must match an owner-confirmed candidate during generation. |
| `tagline` | Nullable string, max 140 characters. |
| `template_key` | `modern_business` or `local_business`. |
| `theme` | Exactly `primary_color`, `secondary_color`, `font_style`; colors are `#RRGGBB`, font style is `modern`, `classic`, or `editorial`. |
| `navigation` | List of at most 6 `{label, href}` entries; label max 40, href is one of `#hero`, `#about`, `#services`, `#highlights`, `#gallery`, `#contact`. |
| `hero` | Exactly `heading` (required string, max 120), `description` (nullable, max 600), `image` (nullable HTTPS URL, max 2048), `button_text` (nullable, max 40). |
| `about` | Exactly `heading` (required string, max 100) and `description` (nullable string, max 1000). |
| `services_or_products` | List of at most 50 `{name, description}` entries; name max 100, description nullable and max 500. Generated names must match confirmed product/service facts. |
| `highlights` | List of at most 8 strings, max 180 characters each. |
| `gallery` | List of at most 10 `{image_url, alt, source_id?}` entries; use either a nullable HTTPS URL (max 2048) or an owner-uploaded image source ID, with required alt text (max 160). Private uploads are served only through an authenticated, project-scoped endpoint. |
| `contact` | Exactly `address` (nullable string, max 250), `phone` (nullable string, max 50), `email` (nullable valid email, max 254), and `social_links` (list of at most 8 `{label, url}` entries; label max 40, URL required HTTPS and max 2048). Generated contact values must match confirmed facts. |
| `footer` | Exactly `copyright_text` (nullable string, max 200) and `show_business_name` (boolean). |
| `content_provenance` | Backend-managed field-path map distinguishing `ai_generated_unverified`, `owner_authored`, and legacy unclassified text. Client-supplied values are recalculated when saving. |

Generation uses all confirmed fact candidates, completes missing confirmed products/services and contact details deterministically, and can write natural paraphrases for descriptive copy. Business names, product/service names, and contact values remain exact-match validated; unsupported numerical and high-risk marketing claims are rejected. The frontend labels AI-generated text as unverified. These safeguards cannot prove the semantics of arbitrary language, so owners should review AI copy before use. Uploaded images remain private and are referenced by source ID rather than copied into website records.

Authenticated project-owner endpoints:

```text
GET    /api/projects/{project}/website
POST   /api/projects/{project}/website/generate
PUT    /api/projects/{project}/website
POST   /api/projects/{project}/website/regenerate-section
GET    /api/projects/{project}/website/revisions
GET    /api/projects/{project}/website/revisions/{revision}
DELETE /api/projects/{project}/website/revisions/{revision}
GET    /api/projects/{project}/sources/{source}/image
```

Only an owner's historical revision can be deleted; deleting the active revision returns a conflict. New versions use the website's monotonic current version, so deleted version numbers are not reused.

Generation and section regeneration are queued. Run the existing database queue worker from `backend/`:

```powershell
php artisan queue:work database --timeout=60
```

Apply migrations with `php artisan migrate`. Run backend tests with `php artisan test`; run frontend checks from `frontend/` using `npm run lint` and `npm run build`. Configure `GEMINI_API_KEY` on the server to enable generation. If a project has no confirmed business facts (or no confirmed business name), the API asks the owner to confirm facts first. A Gemini failure or invalid response is reported with a safe message and does not replace the previously saved website.

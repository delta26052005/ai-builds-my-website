# Laravel backend

The backend is a Laravel REST API for project management, research, confirmed business facts, website generation, and publishing. Follow the [root README](../README.md) for local environment setup.

## Architecture

- Sanctum SPA session authentication protects owner and project-management endpoints.
- Controllers and API resources live under `app/Http/Controllers/Api` and `app/Http/Resources/Api`.
- Research, source extraction, fact extraction, and website generation use the configured database queue. Keep a worker running with `php artisan queue:work database --timeout=60`.
- Uploaded PDFs and images are stored on the private `project_sources` disk. Files are not copied into website records.
- Tavily and Gemini are server-side HTTP integrations configured with `TAVILY_API_KEY`, `GEMINI_API_KEY`, and `GEMINI_MODEL`. Provider credentials and source instructions are not passed into generated client code.

## Website API

Project-owner routes:

```text
GET    /api/projects/{project}/website
POST   /api/projects/{project}/website/generate
PUT    /api/projects/{project}/website
POST   /api/projects/{project}/website/regenerate-section
GET    /api/projects/{project}/website/revisions
GET    /api/projects/{project}/website/revisions/{revision}
DELETE /api/projects/{project}/website/revisions/{revision}
POST   /api/projects/{project}/website/publish
DELETE /api/projects/{project}/website/publish
GET    /api/projects/{project}/sources/{source}/image
```

Public routes:

```text
GET /api/public/websites/{slug}
GET /api/public/websites/{slug}/images/{source}
```

Publishing validates the existing structured website content and project gallery references. It does not generate content or create a revision. The unique public slug is retained after unpublishing. The public website API omits project metadata and provenance; it returns only published content, and responses are not cacheable. Public images are served only for image source IDs in the current published gallery that belong to that website's project. The authenticated project image route remains private.

The renderer accepts the existing fixed `modern_business` and `local_business` templates. Website JSON is validated by `WebsiteContentValidator`; generated markup or scripts are never executed. Only owner-confirmed Phase 5 facts are factual generation inputs. AI-written copy is marked unverified, and saved owner changes are distinguished in `content_provenance`.

## Other business intelligence safeguards

- Tavily research records retain project and source attribution. Unsupported links are not represented as successfully accessed.
- Gemini output is schema-validated before fact candidates are stored; each candidate must have supporting evidence and provenance.
- Candidate facts remain pending until an owner confirms them. Conflicting candidates are retained for review rather than automatically selected.
- Background jobs verify that their project/source still exists before writing results.
- Tests use Laravel HTTP fakes for provider interactions; live credentials are not required to run the test suite.

## Backend checks

Run from this directory:

```powershell
php artisan migrate
php artisan test
vendor/bin/pint --test
```

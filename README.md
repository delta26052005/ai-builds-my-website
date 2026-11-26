# AI Builds My Website

AI Builds My Website is an AI-powered website builder that transforms a business owner's information, documents, links and confirmed business facts into an editable, single-page website.

Built using Laravel, React, Vite and MySQL.

## Screenshots

### 1. Login

![Login](docs/screenshots/login.png)

### 2. Dashboard

![Dashboard](docs/screenshots/new%20Dashboard.png)

### 3. Business Projects

![Business Projects](docs/screenshots/business%20projects.png)

### 4. AI Business Research

![AI Search](docs/screenshots/Ai%20search.png)

### 5. Fact Extraction and Verification

![Extract Facts](docs/screenshots/Extract%20Facts.png)

### 6. Website Builder and Editing

![Website New Version](docs/screenshots/website%20new%20version.png)

### 7. Publishing Website

![Publish Website](docs/screenshots/Publish%20Website.png)

### 8. Published Website

![Published Website](docs/screenshots/published%20website.png)

### 9. Unpublishing Website

![Unpublish Website](docs/screenshots/unpublish%20website.png)

## Key Features

- Business project creation and management.
- PDF document upload and text extraction.
- Business research using Tavily.
- AI-powered fact extraction using Google Gemini.
- Evidence-based fact review and owner confirmation.
- Conflict handling for candidate business information.
- AI-powered single-page website generation.
- Website editing and individual section regeneration.
- Website revision management.
- Public website publishing and unpublishing.
- Predefined React website templates.
- Private source document storage and controlled public image access.

## Technology Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 13, PHP 8.3 |
| Frontend | React, Vite |
| Database | MySQL 8+ |
| Authentication | Laravel Sanctum |
| AI | Google Gemini |
| Web Research | Tavily |
| PDF Extraction | PHP PDF Parser |
| Background Jobs | Laravel Database Queue |
| API | REST API |

## Requirements

- PHP 8.3 and Composer
- MySQL 8+
- Node.js and npm
- Tavily API key for web research
- Gemini API key for AI features

## Local Setup

### Backend Setup

Navigate to the `backend` directory:

```powershell
Copy-Item .env.example .env
composer install
php artisan key:generate
```

Configure your MySQL credentials and API keys in `backend/.env`.

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ai_website_builder
DB_USERNAME=root
DB_PASSWORD=

TAVILY_API_KEY=
GEMINI_API_KEY=
GEMINI_MODEL=gemini-3.5-flash-lite
```

Apply database migrations:

```powershell
php artisan migrate
```

Start the Laravel server:

```powershell
php artisan serve --host=localhost --port=8000
```

Start the queue worker in another terminal:

```powershell
php artisan queue:work database --timeout=60
```

### Frontend Setup

Navigate to the `frontend` directory:

```powershell
npm install
npm run dev
```

Open the Vite development URL in your browser.

The frontend communicates with the Laravel API at `http://localhost:8000/api`.

## Application Workflow

1. Register and log in.
2. Create a business project.
3. Add business details, links and source documents.
4. Extract information from uploaded PDFs.
5. Research the business using Tavily.
6. Extract candidate facts using Gemini.
7. Review evidence and confirm or reject facts.
8. Generate a website using confirmed business information.
9. Edit website sections and save revisions.
10. Publish the website using a public URL.

## Data Safety

- Extracted information is treated as candidate information, not automatically verified.
- Sources and evidence are maintained for fact review.
- Conflicting information is presented for owner verification.
- Uploaded documents and external content are treated as untrusted input.
- Generated HTML and JavaScript are never executed.
- Websites are rendered using predefined React templates.
- Uploaded source documents remain private.
- Public website content is available only after publishing.

## Useful Commands

Backend:

```powershell
php artisan test
vendor/bin/pint --test
```

Frontend:

```powershell
npm run lint
npm run build
```

## Repository Structure

```text
ai-builds-my-website/
├── backend/
├── frontend/
├── docs/
│   └── screenshots/
│       ├── Dashboard.png
│       ├── login.png
│       ├── business projects.png
│       ├── Ai search.png
│       ├── Extract Facts.png
│       ├── Publish Website.png
│       ├── published website.png
│       ├── unpublish website.png
│       ├── website new version.png
│       ├── Delete version.png
│       ├── Delete Draft.png
│       └── new Dashboard.png
├── DECISIONS.md
└── README.md
```

## Project Decisions

See [DECISIONS.md](./DECISIONS.md) for architectural decisions, scope trade-offs and future improvements.

For backend API documentation and additional technical details, see [backend/README.md](./backend/README.md).
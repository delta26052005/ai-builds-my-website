# Implementation decisions

## Publishing

- Publishing is a state change on the existing `GeneratedWebsite`; it does not regenerate content, create revisions, or alter the saved JSON.
- Public slugs are unique, generated once, and retained after unpublishing so that publishing the same website again keeps its URL.
- Publication state and timestamp are stored in the database. Public requests check that state on every request and use `Cache-Control: no-store`, so unpublishing immediately revokes public access without depending on cache invalidation.
- Only the current website content is publicly readable. Historical revisions, project details, uploaded documents, and private storage paths are not part of the public API response.
- Uploaded gallery images use a separate public route. It serves only JPEG, PNG, or WebP sources belonging to the website's project and explicitly referenced by that website's current published gallery. The existing authenticated image endpoint remains owner-scoped.

## Website rendering

- The public page reuses the existing React preview and predefined `modern_business` and `local_business` templates. It does not expose editor controls or execute generated HTML/JavaScript.
- The persisted website JSON schema remains unchanged. Publishing validates the current content against the existing backend schema and project-owned gallery sources.
- Owner-saved edits update the currently published content. Unpublishing is required to make the page private; there is no separate publish-time content snapshot.

## Integrations and tests

- Tavily and Gemini credentials remain in Laravel environment configuration. Automated provider integration tests use HTTP fakes; the normal test suite does not require live API credentials.
- Existing Laravel, Sanctum, MySQL, database queue, and private filesystem infrastructure is reused. No new runtime dependency or external publishing service is introduced.

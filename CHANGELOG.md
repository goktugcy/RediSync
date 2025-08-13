# Changelog

All notable changes to this project will be documented in this file.

## [0.1.9] - 2025-08-12
### Added
- HTTP semantics: ETag/If-None-Match and 304 handling in PSR-15 and Laravel middleware.
- Respect Cache-Control: no-store/private; add Age and X-RediSync-Cache headers on hits.
- Laravel middleware parity with PSR-15.
- README overhaul with Quick nav, CLI docs, and semantics section.
- CI workflow for lint, static analysis, and tests (Redis/MySQL services).

## [0.1.8] - 2025-08-12
### Added
- PSR-15 CacheMiddleware: ETag generation and If-None-Match with 304.
- Cache-Control: no-store compliance and Age/X-RediSync-Cache headers.

## [0.1.7] - 2025-08-11
### Added
- Facade remember() and docs.
- Minor fixes and test improvements.

[0.1.9]: https://github.com/goktugcy/RediSync/compare/v0.1.8...v0.1.9
[0.1.8]: https://github.com/goktugcy/RediSync/compare/v0.1.7...v0.1.8
[0.1.7]: https://github.com/goktugcy/RediSync/compare/v0.1.6...v0.1.7

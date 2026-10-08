# Changelog

## [1.0.0] - 2026-10-08

### Added
- Read-only public business information endpoint with nine explicit fields, null for missing values, HTTPS verification links and a 60/minute rate limit.
- No migrations, seeds, installation or activation performed. Existing settings services own all persistence.
- Dependency scan: no existing extension consumes this new API. Only the new yutiv-stillform template requires >=1.0.0; no existing extension constraint needs changing.

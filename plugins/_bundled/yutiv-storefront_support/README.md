# YUTIV Storefront Support

Read-only public projection required because the existing ecommerce settings API does not expose a dedicated business-information allowlist. It uses existing ModuleSettingsService/PluginSettingsService; no database schema or core changes. Not installed or activated by this task.

See `docs/api/README.md` for the endpoint. Tests under `tests/Unit` inherit PHPUnit TestCase directly and require only the pure projection file; `tests/phpunit.xml` specifies no Laravel/bootstrap/environment/database connection. Run explicitly with this isolated configuration, never the repository's DB-backed test bootstrap.

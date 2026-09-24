# Validation

Completed checks:

- All PHP source files passed `php -l` syntax validation.
- `composer.json` and all JSON data files are valid JSON.
- 51 courses were imported from the current frontend.
- All 51 course keys are unique.
- The repeated display code `EEEX 35XX` is handled through unique course keys.

Run the full Laravel test suite after installing dependencies:

```bash
composer install
php artisan test
```

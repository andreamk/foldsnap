# Utility Commands

Quick reference for the commands available in this project. For what each command does internally, read the corresponding script definition in `composer.json` or `package.json`.

## Composer

- `composer fullcheck` — run all quality gates (use as last step before committing)
- `composer phpcs .` — PHP coding standards check
- `composer phpcbf` — auto-fix PHPCS violations
- `composer plugin-check .` — WordPress.org plugin guidelines
- `composer phpstan` — PHP static analysis
- `composer phpunit` — PHP test suite
- `composer phpunit-install` — install the PHPUnit WordPress test environment
- `composer deploy` — run the deploy script

Filter PHPUnit to a single class or method:

```
composer phpunit -- --filter TestClassName
composer phpunit -- --filter TestClassName::test_method_name
```

## NPM

- `npm run build` — production build
- `npm start` — development build with watch mode
- `npm run lint` — ESLint check
- `npm run lint:fix` — ESLint auto-fix
- `npm run format` — Prettier format
- `npm test` — Jest test suite

Filter Jest to a single file or pattern:

```
npm test -- ComponentName
```

## Scope-Proportional Testing

Run tests proportional to the scope of the change. Run the full suite (`composer fullcheck`) only when:

- The change touches shared utilities or base classes
- The change is cross-cutting (e.g. taxonomy, REST registration, autoloading)
- You are about to commit

# phpdot/template

Swoole-safe Twig integration for the PHPdot ecosystem. Lazy environment, namespaced template paths, auto-discovered extensions via the package manifest, and framework-native exceptions.

No global state. No `Twig_Environment` rebuilds per request. Works under Swoole, RoadRunner, FPM, or any PSR-15 stack.

## Install

```bash
composer require phpdot/template
```

| Requirement | Version |
|-------------|---------|
| PHP | >= 8.3 |
| twig/twig | ^3.10 |
| phpdot/config | ^1.0 |
| phpdot/container | ^1.0 |
| phpdot/package | ^1.0 |

## Quick Start

```php
use PHPdot\Template\EngineFactory;
use PHPdot\Template\View;
use PHPdot\Template\TemplateConfig;

$config  = new TemplateConfig(paths: ['__main__' => [__DIR__ . '/views']]);
$factory = new EngineFactory($config, $manifest, $container);
$view = new View($factory);

echo $view->render('hello.twig', ['name' => 'Omar']);
```

Three objects. The `View` is the only thing your application code needs to touch.

---

## Architecture

### Worker Lifecycle

```
                    BOOT TIME (once per worker)
┌─────────────────────────────────────────────────────────┐
│                                                         │
│   Container resolves EngineFactory (singleton)          │
│       │                                                 │
│       ▼                                                 │
│   First call to ->environment() builds Twig\Environment │
│       │                                                 │
│       ├── FilesystemLoader: register namespaced paths   │
│       ├── DebugExtension if config.debug                │
│       └── Manifest::allServices() → filter by           │
│             Twig\Extension\ExtensionInterface →         │
│             container->get() → addExtension()           │
│       │                                                 │
│       ▼                                                 │
│   Environment cached on the factory instance            │
│                                                         │
└─────────────────────────────────────────────────────────┘
                    RUNTIME (every request)
┌─────────────────────────────────────────────────────────┐
│                                                         │
│   $view->render('page.twig', $context)                 │
│       │                                                 │
│       ▼                                                 │
│   factory->environment() → cached instance              │
│       │                                                 │
│       ▼                                                 │
│   Twig\Error\* → wrapped into PHPdot\Template\Exception │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

### Package Structure

```
src/
├── Exception/
│   ├── TemplateException.php           Base exception
│   ├── TemplateNotFoundException.php   Loader miss
│   ├── TemplateSyntaxException.php     Syntax error (carries line)
│   └── TemplateRenderException.php     Runtime error (carries line)
│
├── TemplateConfig.php                  Immutable configuration (#[Config('template')])
├── EngineFactory.php                   Builds and caches Twig\Environment
└── View.php                        Public-facing API
```

---

## View API

### Render a Template

```php
$view->render('mail/welcome.twig', [
    'user' => $user,
    'url'  => $signupUrl,
]);
```

### Render a Single Block

```php
$view->renderBlock('mail/welcome.twig', 'subject', ['user' => $user]);
```

Useful for templates that hold both an email subject and body in one file.

### Check Existence

```php
if ($view->exists('admin/dashboard.twig')) {
    return $view->render('admin/dashboard.twig');
}
```

### Escape Hatch to Twig

```php
$twig = $view->environment();
$twig->addRuntimeLoader(new MyRuntimeLoader());
```

`environment()` returns the underlying `Twig\Environment` for advanced needs (runtime loaders, custom token parsers, direct access to filters/functions).

---

## Configuration

```php
use PHPdot\Template\TemplateConfig;

$config = new TemplateConfig(
    paths: [
        '__main__' => [__DIR__ . '/views'],
        'admin'    => [__DIR__ . '/admin/views'],
        'mail'     => [__DIR__ . '/mail/views'],
    ],
    cache:           '/var/cache/templates',  // null disables caching
    debug:           false,                   // dump() + verbose errors
    strictVariables: true,                    // undefined vars throw
    charset:         'UTF-8',
    autoReload:      false,                   // recompile on change (dev)
    autoescape:      'html',                  // 'html' | false
);
```

All properties are `readonly`.

### Namespaced Paths

```php
new TemplateConfig(paths: [
    '__main__' => ['/app/views'],
    'admin'    => ['/app/admin/views'],
]);
```

```twig
{% extends '@admin/layout.twig' %}

{% include 'partials/header.twig' %}    {# resolves under __main__ #}
{% include '@admin/sidebar.twig' %}     {# resolves under admin    #}
```

The `__main__` namespace is the default — references without `@namespace/` prefix resolve through it.

### Production vs Development

| Setting | Production | Development |
|---------|-----------|-------------|
| `cache` | absolute path | `null` |
| `debug` | `false` | `true` |
| `autoReload` | `false` | `true` |
| `strictVariables` | `true` | `true` |

---

## Auto-Discovered Extensions

Any class registered with the `phpdot/package` manifest that implements `Twig\Extension\ExtensionInterface` is added to the environment automatically.

```php
namespace Acme\Greet;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class GreetingExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('greet', static fn(string $name): string => "hello, {$name}"),
        ];
    }
}
```

Register it as a singleton in your package manifest — the `EngineFactory` will pick it up at boot:

```twig
{{ greet('world') }}    {# hello, world #}
```

### Extension Lifecycle — Always Singleton

Twig's `Environment::addExtension()` pins the instance you pass for the lifetime of the environment — i.e., for the worker. Marking an extension class `#[Scoped]` or `#[Transient]` is a no-op: the `EngineFactory` resolves the class once at boot, hands it to Twig, and Twig holds onto that single instance forever.

Concretely:

- `#[Singleton]` on an extension class — what you want, behaves as expected.
- `#[Scoped]` / `#[Transient]` on an extension class — silently behaves as singleton. Don't put per-request state on the extension itself; it will leak across coroutines.

For per-request behavior, see *Stateful Extensions* below.

### Stateful Extensions

Extensions that need request-scoped state (current user, locale, route params) should inject `ContainerInterface` and resolve scoped dependencies at call-time, not in the constructor:

```php
final class AuthExtension extends AbstractExtension
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('current_user', fn(): ?User =>
                $this->container->get(SessionInterface::class)->user()
            ),
        ];
    }
}
```

The extension is a singleton (one per worker); the container resolves the scoped dependency per coroutine.

---

## Exceptions

```
TemplateException (extends RuntimeException)
├── TemplateNotFoundException     loader miss
├── TemplateSyntaxException       compile-time syntax error (carries $templateLine)
└── TemplateRenderException       runtime error (carries $templateLine)
```

All leaf exceptions carry the `$template` name that failed:

```php
use PHPdot\Template\Exception\TemplateNotFoundException;
use PHPdot\Template\Exception\TemplateRenderException;
use PHPdot\Template\Exception\TemplateSyntaxException;

try {
    $html = $view->render('page.twig', $context);
} catch (TemplateNotFoundException $e) {
    logger()->warning('Missing template', ['template' => $e->template]);
} catch (TemplateSyntaxException $e) {
    logger()->error('Syntax error', [
        'template' => $e->template,
        'line'     => $e->templateLine,
    ]);
} catch (TemplateRenderException $e) {
    logger()->error('Render failure', [
        'template' => $e->template,
        'line'     => $e->templateLine,
    ]);
}
```

> Note: the property is `$templateLine`, not `$line` — `\Exception::$line` is reserved for the PHP source line of the throw site.

---

## Framework Integration

### DI Wiring

`TemplateConfig`, `EngineFactory`, and `View` are all singletons (one per worker). No scoped wiring needed.

```php
TemplateConfig::class => singleton(fn (Config $c) => new TemplateConfig(
    paths:           $c->array('template.paths'),
    cache:           $c->stringOrNull('template.cache'),
    debug:           $c->bool('template.debug'),
    strictVariables: $c->bool('template.strict_variables'),
    autoReload:      $c->bool('template.auto_reload'),
)),

EngineFactory::class => singleton(),
View::class      => singleton(),
```

With `phpdot/container` autowiring, the `#[Singleton]` attribute on `EngineFactory` and `View` makes the explicit declarations above optional.

### Controller Usage

```php
use PHPdot\Template\View;

final class DashboardController
{
    public function __construct(
        private readonly View $view,
        private readonly ResponseFactory $response,
    ) {}

    public function index(SessionInterface $session): ResponseInterface
    {
        $html = $this->view->render('dashboard.twig', [
            'user' => $session->get('user'),
        ]);

        return $this->response->html($html);
    }
}
```

---

## Swoole Safety

Twig is process-safe but not coroutine-safe out of the box — `Twig\Environment` mutates internal state during template loading. This package guarantees safety by:

| Concern | Mitigation |
|---------|------------|
| Environment mutation | Built once per worker, cached on `EngineFactory` |
| Cache directory writes | `cache: null` for in-memory; otherwise compiled classes use content-hashed names |
| Per-request data | Passed as `$context` to `render()` — never stored on the environment |
| Scoped state in extensions | Inject `ContainerInterface`, resolve at call-time |

The environment is treated as read-only after boot. If you need to register extensions or runtime loaders dynamically, do it once at boot, not per request.

---

## Development

```bash
composer test        # Run tests (26 tests, 59 assertions)
composer analyse     # PHPStan level 10 + strict rules
composer cs-fix      # Apply code style
composer cs-check    # Verify code style (dry run)
composer check       # All three
```

## License

MIT

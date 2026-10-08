<p align="center">
  <a href="https://packagist.org/packages/pollora/debugbar"><img src="https://img.shields.io/packagist/v/pollora/debugbar" alt="Latest version"></a>
  <a href="https://github.com/Pollora/debugbar/actions/workflows/tests.yml"><img src="https://github.com/Pollora/debugbar/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/Pollora/debugbar" alt="License"></a>
</p>

# Pollora Debugbar

Puts WordPress and [Pollora](https://pollora.dev) in [Laravel Debugbar](https://github.com/fruitcake/laravel-debugbar): the `$wpdb` queries next to Eloquent's, the hooks that ran, how WordPress parsed the request, which route or template answered it, and WordPress's phases on the timeline. The long-term goal is to make Query Monitor unnecessary in a Pollora project.

## Installation

```bash
composer require --dev pollora/debugbar
```

It brings `fruitcake/laravel-debugbar` with it. Nothing runs unless Laravel Debugbar is enabled for the request (`DEBUGBAR_ENABLED`, or `APP_DEBUG`; never in production or testing), and as a dev dependency it is not installed by `composer install --no-dev`.

## Tabs

| Tab | Origin | Shows |
| --- | --- | --- |
| Pollora | Pollora | What answered (`Route::wp()`, the template hierarchy and its view, a Laravel route, or WordPress alone), versions, discovery, modules, theme, async actions registered and queued, WordPress constants and drop-ins |
| Doctor | Pollora | A **Run doctor** button: `pollora:doctor`'s web checks on demand, errors first |
| WP Request | WordPress | Rewrite rule, query vars, queried object, main query, true conditionals, template and hierarchy candidates |
| WP Queries | WordPress | `$wpdb` queries with time, full backtrace, rows, errors, duplicates, slow ones and the main query, grouped by component (core, plugin, theme…) |
| WP Hooks | WordPress | Hooks that ran, their callbacks, and those Pollora registered |
| WP HTTP | WordPress | `wp_remote_*` calls: result, time, transport, who made them |
| WP Cache | WordPress | Object cache hits and misses, transients set, OPcache |
| WP Capabilities | WordPress | `current_user_can()` checks, each distinct check once with its count |
| WP Blocks | WordPress | Blocks rendered by type with their time, Pollora's Blade blocks, block bindings |
| WP Assets | WordPress | Scripts, styles and script modules, header or footer, missing dependencies, Vite builds |
| WP Languages | WordPress | Locale and the translation files looked for |
| Timeline | WordPress | `muplugins_loaded` to `shutdown`, beside Debugbar's own measures |

REST and admin-ajax requests end with `exit`, which Laravel Debugbar never sees: this package stores them and sends the `phpdebugbar-id` header, so they appear in the bar's request list of the page that made the call. A `wp_redirect()` keeps its request for the page it leads to.

WordPress queries are traced through core's `log_query_custom_data` and `query` filters, so this works with any `db.php` drop-in, Pollora's included.

Configuration: `php artisan vendor:publish --tag=debugbar-pollora-config`.

## Adding your own data

Tabs from Pollora, WordPress and third parties are told apart: Pollora's tab comes first, WordPress's are prefixed `WP`, and everyone else's come last. Names starting with `wp_` or `pollora` are reserved.

**From a WordPress plugin or theme**, with no dependency on this package (without it, nothing fires the action):

```php
add_action('pollora/debugbar/register', function ($bar): void {
    $bar->table('acme_cart', 'Acme cart', fn (): array => acme_cart_rows(), origin: 'acme-shop');
    $bar->variables('acme_info', 'Acme', fn (): array => ['mode' => 'test'], origin: 'acme-shop');
    $bar->section('wp_request', 'Acme', fn (): array => ['Cart' => acme_cart_id()]);
});

do_action('pollora/debugbar/message', 'Cart rebuilt', 'info', ['items' => 3]);
do_action('pollora/debugbar/start', 'acme-sync');
do_action('pollora/debugbar/stop', 'acme-sync');
```

Query Monitor's `qm/debug` … `qm/emergency`, `qm/start` and `qm/stop` actions keep working too.

**From a package or module**, extend `Pollora\Debugbar\Collector` and tag it:

```php
final class CartCollector extends \Pollora\Debugbar\Collector
{
    public function getName(): string { return 'acme_cart'; }
    public function title(): string { return 'Acme cart'; }
    public function origin(): string { return 'acme-shop'; }
    protected function data(): array { return ['items' => 3]; }
}

$this->app->tag([CartCollector::class], \Pollora\Debugbar\CollectorRegistrar::COLLECTORS_TAG);
```

`widget()` picks `Widget::Variables`, `Widget::Table` (with `columns()`) or `Widget::Queries`. A `Pollora\Debugbar\Contracts\SectionProvider` tagged `pollora.debugbar.sections` adds a section to an existing tab.

**With Laravel Debugbar alone**, `Debugbar::addCollector()` and `debugbar.custom_collectors` work as usual.

## License

MIT. See [LICENSE](LICENSE).

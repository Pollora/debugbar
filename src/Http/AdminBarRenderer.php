<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Http;

use Fruitcake\LaravelDebugbar\LaravelDebugbar;

/**
 * Shows the debug bar on wp-admin pages.
 *
 * WordPress prints those pages itself: `wp-config.php` boots Laravel's
 * providers but no kernel handles the request, so Laravel Debugbar never
 * injects the bar nor stores the request. Here the bar is printed with the
 * admin footer scripts, which also collects and stores it, so the request
 * shows in the bar's request list like any other. The REST calls the block
 * editor makes are then listed in it too.
 */
final class AdminBarRenderer
{
    /**
     * Debugbar's tabs about a request Laravel answered, which a wp-admin page is not.
     */
    public const array LARAVEL_ONLY_COLLECTORS = ['route', 'views', 'session', 'livewire', 'inertia'];

    private bool $rendered = false;

    /**
     * @param  \Closure(): ?LaravelDebugbar  $debugbar  Resolves the bar once it is collecting, null before
     * @param  \Closure(LaravelDebugbar): void  $prepare  Adds the collectors before collection
     * @param  (\Closure(): bool)|null  $isAdminPage  Defaults to isAdminPage()
     */
    public function __construct(
        private readonly \Closure $debugbar,
        private readonly \Closure $prepare,
        private readonly ?\Closure $isAdminPage = null,
    ) {}

    /**
     * Whether this request is a wp-admin page the bar can be printed on.
     *
     * Asked before WordPress loads, when is_admin() and wp_doing_ajax() do
     * not exist yet; the constants they read are only ever defined as true,
     * by `admin.php`, `admin-ajax.php` and the iframe screens, before
     * `wp-config.php`. admin-ajax is answered by WordPressExitResponder, and
     * iframe screens (media upload, theme install) have no room for a bar.
     */
    public static function isAdminPage(): bool
    {
        return defined('WP_ADMIN') && ! defined('DOING_AJAX') && ! defined('IFRAME_REQUEST');
    }

    public function install(): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        add_action('admin_print_footer_scripts', $this->render(...), PHP_INT_MAX, 0);
    }

    public function render(): void
    {
        if ($this->rendered || ! ($this->isAdminPage ?? self::isAdminPage(...))()) {
            return;
        }

        $debugbar = ($this->debugbar)();

        if (! $debugbar instanceof LaravelDebugbar) {
            return;
        }

        $this->rendered = true;

        // A debugging tool must never break the page it watches
        try {
            ($this->prepare)($debugbar);
            $renderer = $debugbar->getJavascriptRenderer();
            $bar = "<!-- Laravel Debugbar Widget -->\n".$renderer->renderHead().$renderer->render();
        } catch (\Throwable $throwable) {
            report($throwable);

            return;
        }

        echo $bar;
    }
}

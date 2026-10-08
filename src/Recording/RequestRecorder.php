<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Recording;

/**
 * Records what WordPress does during the request, for the collectors to read.
 *
 * Installed right before WordPress loads, so it sees every phase from
 * `muplugins_loaded` on. It only listens: nothing is computed until the bar
 * collects, and nothing it records changes what WordPress does.
 */
final class RequestRecorder
{
    /**
     * The actions whose callbacks are timed for the timeline, in firing order.
     */
    public const array NOTABLE_ACTIONS = [
        'muplugins_loaded',
        'plugins_loaded',
        'setup_theme',
        'after_setup_theme',
        'init',
        'wp_loaded',
        'parse_request',
        'wp',
        'template_redirect',
        'wp_head',
        'wp_footer',
        'shutdown',
    ];

    /**
     * The template types WordPress asks a hierarchy for (`{type}_template_hierarchy`).
     */
    public const array TEMPLATE_TYPES = [
        'index', '404', 'archive', 'author', 'category', 'tag', 'taxonomy', 'date',
        'embed', 'home', 'frontpage', 'privacypolicy', 'page', 'paged', 'search',
        'single', 'singular', 'attachment',
    ];

    private bool $installed = false;

    private ?float $bootedAt = null;

    /**
     * First callback start and last callback end of each notable action.
     *
     * @var array<string, array{start: float, end: float}>
     */
    private array $phases = [];

    /**
     * Candidate lists WordPress built, by template type, in the order asked.
     *
     * @var array<string, list<string>>
     */
    private array $hierarchies = [];

    private ?string $templateInclude = null;

    /**
     * How many times each hook ran, filters included (only with filter counts on).
     *
     * @var array<string, int>
     */
    private array $hookCalls = [];

    /**
     * Start listening. Call once WordPress's plugin API is loaded and before
     * `wp-settings.php` runs.
     */
    public function install(bool $countAllHooks = false): void
    {
        if ($this->installed || ! function_exists('add_action')) {
            return;
        }

        $this->installed = true;
        $this->bootedAt = microtime(true);

        foreach (self::NOTABLE_ACTIONS as $action) {
            add_action($action, fn () => $this->phaseStarts($action), PHP_INT_MIN, 0);
            add_action($action, fn () => $this->phaseEnds($action), PHP_INT_MAX, 0);
        }

        foreach (self::TEMPLATE_TYPES as $type) {
            add_filter("{$type}_template_hierarchy", fn (mixed $templates): mixed => $this->hierarchy($type, $templates), PHP_INT_MAX);
        }

        add_filter('template_include', fn (mixed $template): mixed => $this->recordTemplate($template), PHP_INT_MAX);

        if ($countAllHooks) {
            add_action('all', function (string $hook): void {
                $this->countCall($hook);
            }, 10, 1);
        }
    }

    public function isInstalled(): bool
    {
        return $this->installed;
    }

    /**
     * When WordPress started loading (right before `wp-settings.php`).
     */
    public function bootedAt(): ?float
    {
        return $this->bootedAt;
    }

    /**
     * @return array<string, array{start: float, end: float}>
     */
    public function phases(): array
    {
        return $this->phases;
    }

    /**
     * @return array<string, list<string>>
     */
    public function hierarchies(): array
    {
        return $this->hierarchies;
    }

    /**
     * The template `template_include` settled on, after every other filter.
     */
    public function template(): ?string
    {
        return $this->templateInclude;
    }

    /**
     * @return array<string, int>|null Null when filter counts are off
     */
    public function hookCalls(): ?array
    {
        return $this->hookCalls === [] ? null : $this->hookCalls;
    }

    public function reset(): void
    {
        $this->phases = [];
        $this->hierarchies = [];
        $this->templateInclude = null;
        $this->hookCalls = [];
        $this->bootedAt = null;
    }

    private function phaseStarts(string $action): void
    {
        $this->phases[$action] ??= ['start' => microtime(true), 'end' => microtime(true)];
    }

    private function phaseEnds(string $action): void
    {
        if (isset($this->phases[$action])) {
            $this->phases[$action]['end'] = microtime(true);
        }
    }

    private function hierarchy(string $type, mixed $templates): mixed
    {
        if (is_array($templates)) {
            $this->hierarchies[$type] = array_values(array_map(strval(...), array_filter($templates, is_scalar(...))));
        }

        return $templates;
    }

    private function recordTemplate(mixed $template): mixed
    {
        if (is_string($template) && $template !== '') {
            $this->templateInclude = $template;
        }

        return $template;
    }

    private function countCall(string $hook): void
    {
        $this->hookCalls[$hook] = ($this->hookCalls[$hook] ?? 0) + 1;
    }
}

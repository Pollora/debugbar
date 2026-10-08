<?php

declare(strict_types=1);

namespace Pollora\Debugbar;

use DebugBar\DataCollector\DataCollectorInterface;
use DebugBar\DataCollector\TimeDataCollector;
use Fruitcake\LaravelDebugbar\LaravelDebugbar;
use Illuminate\Contracts\Container\Container;
use Pollora\Debugbar\Collectors\DoctorCollector;
use Pollora\Debugbar\Collectors\PolloraCollector;
use Pollora\Debugbar\Collectors\WpAssetsCollector;
use Pollora\Debugbar\Collectors\WpBlocksCollector;
use Pollora\Debugbar\Collectors\WpCacheCollector;
use Pollora\Debugbar\Collectors\WpCapabilitiesCollector;
use Pollora\Debugbar\Collectors\WpHooksCollector;
use Pollora\Debugbar\Collectors\WpHttpCollector;
use Pollora\Debugbar\Collectors\WpLanguagesCollector;
use Pollora\Debugbar\Collectors\WpQueriesCollector;
use Pollora\Debugbar\Collectors\WpRequestCollector;
use Pollora\Debugbar\Collectors\WpTimelineCollector;
use Pollora\Debugbar\Contracts\SectionProvider;
use Pollora\Debugbar\Recording\AsyncRecorder;
use Pollora\Debugbar\Recording\BlockRecorder;
use Pollora\Debugbar\Recording\CacheRecorder;
use Pollora\Debugbar\Recording\CapabilityRecorder;
use Pollora\Debugbar\Recording\HttpRecorder;
use Pollora\Debugbar\Recording\LanguageRecorder;
use Pollora\Debugbar\Recording\QueryTracer;
use Pollora\Debugbar\Recording\RequestRecorder;
use Pollora\Debugbar\Support\Components;
use Pollora\Hook\Domain\Contract\Action;
use Pollora\Hook\Domain\Contract\Filter;

/**
 * Adds every tab to the bar, once: Pollora's own, those tagged in the
 * container, and those WordPress code registers through the
 * `pollora/debugbar/register` action.
 *
 * Called when the application has booted, and again before a REST or
 * admin-ajax request is collected, since those exit before Laravel's booted
 * callbacks run. Every tab is added on every request the bar is on — the
 * assets request included, which rebuilds its list of scripts from the
 * collectors present.
 */
final class CollectorRegistrar
{
    /** Container tag for DataCollectorInterface instances (level 2). */
    public const string COLLECTORS_TAG = 'pollora.debugbar.collectors';

    /** Container tag for SectionProvider instances. */
    public const string SECTIONS_TAG = 'pollora.debugbar.sections';

    private bool $registered = false;

    public function __construct(
        private readonly Container $container,
        private readonly RequestRecorder $recorder,
    ) {}

    public function register(LaravelDebugbar $debugbar): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        foreach ($this->builtIns($debugbar) as $collector) {
            $this->add($debugbar, $collector);
        }

        foreach ($this->container->tagged(self::COLLECTORS_TAG) as $collector) {
            if ($collector instanceof DataCollectorInterface) {
                $this->add($debugbar, $collector);
            }
        }

        $registry = new Registry;

        if (function_exists('do_action')) {
            do_action('pollora/debugbar/register', $registry);
        }

        foreach ($registry->collectors() as $collector) {
            $this->add($debugbar, $collector);
        }

        foreach ($this->container->tagged(self::SECTIONS_TAG) as $provider) {
            if ($provider instanceof SectionProvider) {
                $this->section($debugbar, $provider->tab(), $provider->title(), $provider->values(...));
            }
        }

        foreach ($registry->sections() as $section) {
            $this->section($debugbar, $section['tab'], $section['title'], $section['values']);
        }
    }

    public function isRegistered(): bool
    {
        return $this->registered;
    }

    /**
     * @return list<DataCollectorInterface>
     */
    private function builtIns(LaravelDebugbar $debugbar): array
    {
        $config = $this->container->make('config');
        $on = static fn (string $name): bool => (bool) $config->get("debugbar-pollora.collectors.{$name}", true);
        $collectors = [];

        if ($on('pollora')) {
            $collectors[] = new PolloraCollector($this->container, $this->container->make(AsyncRecorder::class));
        }

        if ($on('wp_request')) {
            $collectors[] = new WpRequestCollector($this->recorder);
        }

        if ($on('wp_queries')) {
            $threshold = $config->get('debugbar-pollora.options.wp_queries.slow_threshold', 50);

            $collectors[] = new WpQueriesCollector(
                is_numeric($threshold) ? (float) $threshold : null,
                (int) $config->get('debugbar-pollora.options.wp_queries.soft_limit', 100),
                (int) $config->get('debugbar-pollora.options.wp_queries.hard_limit', 500),
                $this->container->make(Components::class),
                $config->get('debugbar-pollora.options.wp_queries.trace', true) ? $this->container->make(QueryTracer::class) : null,
            );
        }

        if ($on('wp_hooks')) {
            $collectors[] = new WpHooksCollector($this->recorder, $this->polloraHookServices());
        }

        $components = $this->container->make(Components::class);

        if ($on('wp_http')) {
            $collectors[] = new WpHttpCollector($this->container->make(HttpRecorder::class), $components);
        }

        if ($on('wp_cache')) {
            $collectors[] = new WpCacheCollector($this->container->make(CacheRecorder::class), $components);
        }

        if ($on('wp_capabilities')) {
            $collectors[] = new WpCapabilitiesCollector($this->container->make(CapabilityRecorder::class), $components);
        }

        if ($on('wp_blocks')) {
            $collectors[] = new WpBlocksCollector($this->container->make(BlockRecorder::class));
        }

        if ($on('wp_assets')) {
            $collectors[] = new WpAssetsCollector($this->container);
        }

        if ($on('wp_languages')) {
            $collectors[] = new WpLanguagesCollector($this->container->make(LanguageRecorder::class));
        }

        if ($on('doctor')) {
            $collectors[] = new DoctorCollector('/'.trim((string) $config->get('debugbar.route_prefix', '_debugbar'), '/').'/pollora/doctor');
        }

        if ($on('wp_timeline') && $debugbar->hasCollector('time')) {
            $time = $debugbar->getCollector('time');

            if ($time instanceof TimeDataCollector) {
                $collectors[] = new WpTimelineCollector($this->recorder, $time);
            }
        }

        return $collectors;
    }

    /**
     * @return list<object>
     */
    private function polloraHookServices(): array
    {
        $services = [];

        foreach ([Action::class, Filter::class] as $contract) {
            if ($this->container->bound($contract)) {
                $services[] = $this->container->make($contract);
            }
        }

        return $services;
    }

    private function add(LaravelDebugbar $debugbar, DataCollectorInterface $collector): void
    {
        if ($debugbar->hasCollector($collector->getName())) {
            $debugbar->addMessage(sprintf('pollora/debugbar: a tab named "%s" already exists; the second one was left out.', $collector->getName()), 'warning');

            return;
        }

        $debugbar->addCollector($collector);
    }

    /**
     * @param  \Closure(): array<string, mixed>  $values
     */
    private function section(LaravelDebugbar $debugbar, string $tab, string $title, \Closure $values): void
    {
        $collector = $debugbar->hasCollector($tab) ? $debugbar->getCollector($tab) : null;

        if ($collector instanceof Collector) {
            $collector->addSection($title, $values);
        }
    }
}

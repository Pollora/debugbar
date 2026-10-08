<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Collectors;

use Pollora\Debugbar\Collector;
use Pollora\Debugbar\Origin;
use Pollora\Debugbar\Recording\RequestRecorder;
use Pollora\Debugbar\Recording\SiteRecorder;
use Pollora\Debugbar\Support\Components;

/**
 * How WordPress understood the request: rewrite rule, query, queried object,
 * conditionals and the template hierarchy it built; in wp-admin, the screen;
 * on a multisite, the site and the switches between sites.
 */
final class WpRequestCollector extends Collector
{
    /**
     * The conditional tags evaluated at the end of the request; only the true
     * ones are shown.
     */
    public const array CONDITIONALS = [
        'is_404', 'is_admin', 'is_archive', 'is_attachment', 'is_author', 'is_blog_admin',
        'is_category', 'is_comment_feed', 'is_customize_preview', 'is_date', 'is_day',
        'is_embed', 'is_favicon', 'is_feed', 'is_front_page', 'is_home', 'is_main_network',
        'is_main_site', 'is_month', 'is_network_admin', 'is_page', 'is_page_template',
        'is_paged', 'is_post_type_archive', 'is_preview', 'is_privacy_policy', 'is_robots',
        'is_rtl', 'is_search', 'is_single', 'is_singular', 'is_ssl', 'is_tag', 'is_tax',
        'is_time', 'is_trackback', 'is_user_admin', 'is_year',
    ];

    public function __construct(
        private readonly RequestRecorder $recorder,
        private readonly ?SiteRecorder $sites = null,
        private readonly ?Components $components = null,
    ) {}

    public function getName(): string
    {
        return 'wp_request';
    }

    public function title(): string
    {
        return 'WP Request';
    }

    public function origin(): string
    {
        return Origin::WORDPRESS;
    }

    public function icon(): string
    {
        return 'search';
    }

    public function position(): int
    {
        return 10;
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(): array
    {
        global $wp, $wp_query, $wp_the_query;

        $data = [];

        if (is_object($wp)) {
            $data['Request'] = (string) ($wp->request ?? '');
            $data['Matched rule'] = $wp->matched_rule ?? null;
            $data['Matched query'] = $wp->matched_query ?? null;
            $data['Query vars'] = (array) ($wp->query_vars ?? []);
        }

        $data['Queried object'] = $this->queriedObject();

        if (is_object($wp_the_query) && isset($wp_the_query->query_vars)) {
            $data['Main query'] = array_filter(
                (array) $wp_the_query->query_vars,
                static fn (mixed $value): bool => $value !== '' && $value !== [] && $value !== null && $value !== false && $value !== 0,
            );
            $data['Main query results'] = sprintf(
                '%d of %d found, %d page(s)',
                (int) ($wp_the_query->post_count ?? 0),
                (int) ($wp_the_query->found_posts ?? 0),
                (int) ($wp_the_query->max_num_pages ?? 0),
            );
        }

        // Conditional tags complain through _doing_it_wrong() before the query exists.
        if (isset($wp_query)) {
            $data['Conditionals'] = implode(', ', $this->trueConditionals());
        }

        $data['Template'] = $this->recorder->template() !== null ? $this->relativePath($this->recorder->template()) : null;
        $data['Template hierarchy'] = $this->recorder->hierarchies() !== [] ? $this->recorder->hierarchies() : null;

        return [...$data, ...$this->adminScreen(), ...$this->multisite()];
    }

    /**
     * The admin page and screen, as Query Monitor's Admin panel shows them.
     *
     * @return array<string, mixed>
     */
    private function adminScreen(): array
    {
        global $pagenow, $hook_suffix;

        // $pagenow is set once WordPress has parsed the URL; before that,
        // is_admin() has nothing reliable to say
        if (! is_string($pagenow) || ! function_exists('is_admin') || ! is_admin() || ! function_exists('get_current_screen')) {
            return [];
        }

        $screen = get_current_screen();

        return array_filter([
            'Admin page' => $pagenow,
            'Hook suffix' => is_string($hook_suffix) && $hook_suffix !== '' ? $hook_suffix : null,
            'Screen' => $screen instanceof \WP_Screen ? array_filter([
                'id' => $screen->id,
                'base' => $screen->base,
                'post type' => $screen->post_type,
                'taxonomy' => $screen->taxonomy,
                'block editor' => $screen->is_block_editor() ? 'yes' : null,
            ], static fn (mixed $value): bool => $value !== '' && $value !== null) : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * The site and network that answered, and each switch between sites.
     *
     * @return array<string, mixed>
     */
    private function multisite(): array
    {
        // ms-settings.php sets the network; a single site never has one
        if (! is_object($GLOBALS['current_site'] ?? null) || ! function_exists('is_multisite') || ! is_multisite()) {
            return [];
        }

        $switched = function_exists('ms_is_switched') && ms_is_switched();
        // While switched, the site that answered is the first one switched away from
        $stack = $GLOBALS['_wp_switched_stack'] ?? [];
        $site = $switched && is_array($stack) && isset($stack[0]) ? (int) $stack[0] : get_current_blog_id();

        $data = [
            'Site' => sprintf('#%d%s', $site, is_main_site($site) ? ' (main site)' : ''),
            'Network' => function_exists('get_current_network_id') ? '#'.get_current_network_id() : null,
        ];

        $switches = [];

        foreach ($this->sites?->switches() ?? [] as $switch) {
            $switches[] = sprintf(
                '%s #%d → #%d%s',
                $switch['context'],
                $switch['from'],
                $switch['to'],
                $this->components instanceof Components && $switch['frames'] !== [] ? ' ('.$this->components->ofTrace($switch['frames']).')' : '',
            );
        }

        $data['Site switches'] = $switches !== [] ? $switches : 'none';

        if ($switched) {
            $data['Still switched'] = sprintf('yes, to #%d: restore_current_blog() was not called for every switch_to_blog()', get_current_blog_id());
        }

        return $data;
    }

    /**
     * @return list<string>
     */
    private function trueConditionals(): array
    {
        $true = [];

        foreach (self::CONDITIONALS as $conditional) {
            if (function_exists($conditional) && $conditional()) {
                $true[] = $conditional.'()';
            }
        }

        return $true;
    }

    private function queriedObject(): ?string
    {
        if (! function_exists('get_queried_object')) {
            return null;
        }

        $object = get_queried_object();

        return match (true) {
            $object instanceof \WP_Post => sprintf('post #%d (%s) %s', $object->ID, $object->post_type, $object->post_title),
            $object instanceof \WP_Term => sprintf('term #%d (%s) %s', $object->term_id, $object->taxonomy, $object->name),
            $object instanceof \WP_User => sprintf('user #%d %s', $object->ID, $object->display_name),
            $object instanceof \WP_Post_Type => sprintf('post type %s', $object->name),
            default => null,
        };
    }
}

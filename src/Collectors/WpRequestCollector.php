<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Collectors;

use Pollora\Debugbar\Collector;
use Pollora\Debugbar\Origin;
use Pollora\Debugbar\Recording\RequestRecorder;

/**
 * How WordPress understood the request: rewrite rule, query, queried object,
 * conditionals and the template hierarchy it built.
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
        return 'brand-wordpress';
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

        $data['Template'] = $this->recorder->template() !== null ? $this->relative($this->recorder->template()) : null;
        $data['Template hierarchy'] = $this->recorder->hierarchies() !== [] ? $this->recorder->hierarchies() : null;

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

    private function relative(string $path): string
    {
        $base = function_exists('base_path') ? rtrim(base_path(), '/').'/' : '';

        return $base !== '' && str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}

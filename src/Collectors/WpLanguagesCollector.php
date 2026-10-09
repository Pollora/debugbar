<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Collectors;

use Pollora\Debugbar\Collector;
use Pollora\Debugbar\Origin;
use Pollora\Debugbar\Recording\LanguageRecorder;
use Pollora\Debugbar\Widget;

/**
 * The locale, and the translation files WordPress looked for by text domain.
 */
final class WpLanguagesCollector extends Collector
{
    public function __construct(
        private readonly LanguageRecorder $recorder,
    ) {}

    public function getName(): string
    {
        return 'wp_languages';
    }

    public function title(): string
    {
        return 'WP Languages';
    }

    public function origin(): string
    {
        return Origin::WORDPRESS;
    }

    public function icon(): string
    {
        return 'flag';
    }

    public function widget(): Widget
    {
        return Widget::Table;
    }

    public function position(): int
    {
        return 90;
    }

    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return ['file' => 'File', 'found' => 'Found', 'kind' => 'Kind'];
    }

    /**
     * @return array<string, array{file: string, found: string, kind: string}>
     */
    protected function data(): array
    {
        $rows = [];

        if (function_exists('determine_locale')) {
            $rows['locale'] = ['file' => determine_locale().(function_exists('get_user_locale') ? ' (user: '.get_user_locale().')' : ''), 'found' => '', 'kind' => ''];
        }

        foreach ($this->recorder->files() as $index => $file) {
            $rows[sprintf('%d. %s', $index + 1, $file['domain'])] = [
                'file' => $this->relativePath($file['file']),
                'found' => $file['found'] ? 'yes' : 'no',
                'kind' => $file['kind'],
            ];
        }

        return $rows;
    }
}

<?php

declare(strict_types=1);

namespace Pollora\Debugbar\Recording;

/**
 * Records the translation files WordPress looked for, and whether each exists.
 */
final class LanguageRecorder
{
    private bool $installed = false;

    /**
     * @var list<array{domain: string, file: string, found: bool, kind: string}>
     */
    private array $files = [];

    public function install(): void
    {
        if ($this->installed || ! function_exists('add_filter')) {
            return;
        }

        $this->installed = true;

        // WordPress 6.5+ asks for .l10n.php first, then .mo, through load_translation_file
        add_filter('load_translation_file', function (mixed $file, mixed $domain): mixed {
            $this->record((string) $domain, (string) $file, 'php / mo');

            return $file;
        }, PHP_INT_MAX, 2);
        add_filter('load_script_translation_file', function (mixed $file, mixed $handle, mixed $domain): mixed {
            if (is_string($file)) {
                $this->record((string) $domain, $file, 'script ('.$handle.')');
            }

            return $file;
        }, PHP_INT_MAX, 3);
    }

    /**
     * @return list<array{domain: string, file: string, found: bool, kind: string}>
     */
    public function files(): array
    {
        return $this->files;
    }

    public function reset(): void
    {
        $this->files = [];
    }

    private function record(string $domain, string $file, string $kind): void
    {
        $this->files[] = ['domain' => $domain, 'file' => $file, 'found' => $file !== '' && is_readable($file), 'kind' => $kind];
    }
}

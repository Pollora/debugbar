<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use DebugBar\DataFormatter\DataFormatter;
use Pollora\Debugbar\Collectors\WpCacheCollector;
use Pollora\Debugbar\Collectors\WpLanguagesCollector;
use Pollora\Debugbar\Recording\CacheRecorder;
use Pollora\Debugbar\Recording\LanguageRecorder;
use Pollora\Debugbar\Support\Components;

afterEach(function (): void {
    unset($GLOBALS['wp_object_cache']);
});

function cacheData(?CacheRecorder $recorder = null): array
{
    $collector = new WpCacheCollector($recorder ?? new CacheRecorder, new Components([]));

    // The raw values, before the bar's formatter turns them into text
    return (new ReflectionMethod($collector, 'data'))->invoke($collector);
}

describe('WP Cache', function (): void {
    beforeEach(function (): void {
        // Brain Monkey keeps a function defined once a test has mocked it
        Functions\when('wp_using_ext_object_cache')->justReturn(false);
    });

    it('computes the hit ratio of the object cache', function (): void {
        $GLOBALS['wp_object_cache'] = (object) ['cache_hits' => 3, 'cache_misses' => 1];

        $data = cacheData();

        expect($data['Hits'])->toBe(3)
            ->and($data['Misses'])->toBe(1)
            ->and($data['Hit ratio'])->toBe('75.0 %');
    });

    it('gives no ratio before the cache served anything', function (): void {
        $GLOBALS['wp_object_cache'] = (object) ['cache_hits' => 0, 'cache_misses' => 0];

        expect(cacheData()['Hit ratio'])->toBeNull();
    });

    it('says the cache lives for this request only when no persistent one is installed', function (): void {
        $GLOBALS['wp_object_cache'] = new stdClass;

        $data = cacheData();

        expect($data['Object cache'])->toBe('in memory for this request only (stdClass)')
            ->and($data['Hits'])->toBeNull()
            ->and($data['Hit ratio'])->toBeNull();
    });

    it('names the class of a persistent object cache', function (): void {
        Functions\when('wp_using_ext_object_cache')->justReturn(true);
        $GLOBALS['wp_object_cache'] = new ArrayObject;

        expect(cacheData()['Object cache'])->toBe('persistent (ArrayObject)');
    });

    it('shows nothing about the object cache before WordPress created it', function (): void {
        expect(cacheData()['Object cache'])->toBeNull();
    });

    it('lists the transients set, with their expiration, size and owner', function (): void {
        $recorder = new CacheRecorder;
        $record = new ReflectionMethod($recorder, 'record');
        $record->invoke($recorder, 'acme_rates', 'abc', 3600, false);
        $record->invoke($recorder, 'acme_feed', 'x', 0, true);

        $transients = cacheData($recorder)['Transients set'];

        expect($transients)->toHaveCount(2)
            ->and($transients[0])->toStartWith('acme_rates · expires in 3600 s · '.strlen(serialize('abc')).' bytes · ')
            ->and($transients[1])->toStartWith('site: acme_feed · no expiration · ');
    });

    it('says there were no transients', function (): void {
        expect(cacheData()['Transients set'])->toBe('none');
    });

    it('reports the state of OPcache in one line', function (): void {
        expect(cacheData()['OPcache'])->toMatch('/^(not installed|off|on, \d+(\.\d)? % hits)$/');
    });
});

describe('WP Languages', function (): void {
    function languageRows(LanguageRecorder $recorder): array
    {
        return (new WpLanguagesCollector($recorder))->setDataFormatter(new DataFormatter)->collect()['data']['data'];
    }

    function languageFile(LanguageRecorder $recorder, string $file, string $domain, string $kind = 'php / mo'): void
    {
        (new ReflectionMethod($recorder, 'record'))->invoke($recorder, $domain, $file, $kind);
    }

    beforeEach(function (): void {
        Functions\when('determine_locale')->justReturn('fr_FR');
        Functions\when('get_user_locale')->justReturn('en_US');
    });

    it('starts with the locale of the site and the one of the user', function (): void {
        expect(languageRows(new LanguageRecorder)['locale']['file'])->toBe('fr_FR (user: en_US)');
    });

    it('lists the translation files asked for, numbered, with whether each was found', function (): void {
        $recorder = new LanguageRecorder;
        languageFile($recorder, __FILE__, 'acme');
        languageFile($recorder, '/nowhere/acme-fr_FR.mo', 'acme', 'script (acme-editor)');
        languageFile($recorder, '', 'other');

        $rows = languageRows($recorder);

        expect(array_keys($rows))->toBe(['locale', '1. acme', '2. acme', '3. other'])
            ->and($rows['1. acme']['found'])->toBe('yes')
            ->and($rows['2. acme'])->toMatchArray(['found' => 'no', 'kind' => 'script (acme-editor)'])
            ->and($rows['3. other']['found'])->toBe('no');
    });
});

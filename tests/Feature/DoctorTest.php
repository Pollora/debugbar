<?php

declare(strict_types=1);

use Pollora\Debugbar\Collectors\DoctorCollector;
use Pollora\Debugbar\Http\DoctorController;
use Pollora\Doctor\Application\Services\Doctor;
use Pollora\Doctor\Domain\Contracts\CheckInterface;
use Pollora\Doctor\Domain\Enums\RunContext;
use Pollora\Doctor\Domain\Models\CheckResult;

function check(string $id, CheckResult $result): CheckInterface
{
    return new class($id, $result) implements CheckInterface
    {
        public function __construct(private string $name, private CheckResult $result) {}

        public function id(): string
        {
            return $this->name;
        }

        public function label(): string
        {
            return ucfirst($this->name);
        }

        public function runsIn(): array
        {
            return [RunContext::Http];
        }

        public function run(RunContext $context): CheckResult
        {
            return $this->result;
        }
    };
}

beforeEach(function (): void {
    $this->app['router']->get('_debugbar/pollora/doctor', DoctorController::class);
    $this->app->instance(Doctor::class, new Doctor([
        check('cache', CheckResult::ok('Fine.')),
        check('patterns', CheckResult::error('Broken pattern.', ['patterns/hero.php'], 'Fix the markup.')),
        check('theme', CheckResult::warning('No build.')),
    ]));
});

it('refuses callers outside local and private addresses, as Debugbar does for stored requests', function (): void {
    // Testbench runs as "testing", where Debugbar keeps its storage closed
    $this->getJson('_debugbar/pollora/doctor')
        ->assertForbidden()
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'debugbar.storage.open'));
});

it('runs the web checks and lists errors first', function (): void {
    config(['debugbar.force_allow_enable' => true, 'debugbar.enabled' => true, 'debugbar.storage.open' => true]);
    $this->collectingDebugbar();

    $this->getJson('_debugbar/pollora/doctor')
        ->assertOk()
        ->assertJsonPath('checks.0.id', 'patterns')
        ->assertJsonPath('checks.0.fix', 'Fix the markup.')
        ->assertJsonPath('checks.0.details', ['patterns/hero.php'])
        ->assertJsonPath('checks.1.status', 'warning')
        ->assertJsonPath('checks.2.status', 'ok');
});

it('gives its tab the address to call and a widget of its own, inlined', function (): void {
    $collector = new DoctorCollector('/_debugbar/pollora/doctor');

    expect($collector->collect()['data'])->toBe(['url' => '/_debugbar/pollora/doctor'])
        ->and($collector->getWidgets()['pollora_doctor']['widget'])->toBe('PhpDebugBar.Widgets.PolloraDoctorWidget')
        ->and($collector->getAssets()['inline_js']['pollora-doctor-widget'])->toContain('PolloraDoctorWidget');
});

it('lists errors, then warnings, then passes, then skipped checks, with everything each one says', function (): void {
    $this->app->instance(Doctor::class, new Doctor([
        check('skipped', CheckResult::skipped('Not here.')),
        check('fine', CheckResult::ok('Fine.', ['one'])),
        check('warned', CheckResult::warning('Careful.', ['two'], 'Do this.')),
        check('broken', CheckResult::error('Broken.', ['three'], 'Fix that.')),
    ]));
    config(['debugbar.force_allow_enable' => true, 'debugbar.enabled' => true, 'debugbar.storage.open' => true]);
    $this->collectingDebugbar();

    $checks = $this->getJson('_debugbar/pollora/doctor')->assertOk()->json('checks');

    expect(array_column($checks, 'status'))->toBe(['error', 'warning', 'ok', 'skipped'])
        ->and($checks[0])->toBe(['id' => 'broken', 'label' => 'Broken', 'status' => 'error', 'summary' => 'Broken.', 'details' => ['three'], 'fix' => 'Fix that.'])
        ->and($checks[1])->toBe(['id' => 'warned', 'label' => 'Warned', 'status' => 'warning', 'summary' => 'Careful.', 'details' => ['two'], 'fix' => 'Do this.'])
        ->and($checks[2])->toBe(['id' => 'fine', 'label' => 'Fine', 'status' => 'ok', 'summary' => 'Fine.', 'details' => ['one'], 'fix' => null])
        ->and($checks[3]['id'])->toBe('skipped');
});

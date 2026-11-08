<?php

declare(strict_types=1);

use Touhidurabir\EnvLens\Analysis\Baseline;
use Touhidurabir\EnvLens\Analysis\Finding;
use Touhidurabir\EnvLens\Analysis\FindingCode;
use Touhidurabir\EnvLens\Exceptions\InvalidBaseline;

function undeclaredFinding(string $key, int $line = 3): Finding
{
    return new Finding(FindingCode::UndeclaredKey, "{$key} undeclared", $key, 'config/a.php', $line, "config/a.php:{$line}");
}

function outsideFinding(?string $key, string $file = 'app/A.php', int $line = 3): Finding
{
    return new Finding(FindingCode::EnvOutsideConfig, 'env() outside config', $key, $file, $line);
}

it('records only findings that may be accepted', function () {
    $baseline = Baseline::fromFindings([
        undeclaredFinding('A'),
        outsideFinding('B'),
        new Finding(FindingCode::ExampleSecret, 'secret', 'C', '.env.example', 1),
        new Finding(FindingCode::Syntax, 'syntax', null, '.env.example', 2),
        new Finding(FindingCode::ScanWarning, 'scan'),
    ]);

    expect($baseline->entries())->toBe([
        ['code' => 'env-outside-config', 'key' => 'B', 'file' => 'app/A.php'],
        ['code' => 'undeclared-key', 'key' => 'A'],
    ]);
});

it('ignores recorded findings even when their line moved', function () {
    $result = Baseline::fromFindings([undeclaredFinding('A', 3), outsideFinding('B', line: 3)])
        ->apply([undeclaredFinding('A', 90), outsideFinding('B', line: 90), undeclaredFinding('NEW')]);

    expect($result['accepted'])->toBe(2)
        ->and(array_map(fn (Finding $f) => $f->key, $result['kept']))->toBe(['NEW'])
        ->and($result['stale'])->toBe([]);
});

it('never accepts a finding it cannot record, even with a matching key', function () {
    $secret = new Finding(FindingCode::ExampleSecret, 'secret', 'A', '.env.example', 1);

    expect(Baseline::fromFindings([undeclaredFinding('A')])->apply([$secret])['kept'])->toBe([$secret]);
});

it('tells apart the same env() call in another file', function () {
    $result = Baseline::fromFindings([outsideFinding('B', 'app/A.php')])->apply([outsideFinding('B', 'app/Z.php')]);

    expect($result['accepted'])->toBe(0)->and($result['kept'])->toHaveCount(1);
});

it('matches env() calls with a dynamic key by file', function () {
    $result = Baseline::fromFindings([outsideFinding(null)])->apply([outsideFinding(null)]);

    expect($result['accepted'])->toBe(1);
});

it('reports entries that no longer match as stale info findings', function () {
    $result = Baseline::fromFindings([undeclaredFinding('GONE')])->apply([]);

    expect($result['kept'])->toBe([])
        ->and($result['stale'])->toHaveCount(1)
        ->and($result['stale'][0]->code)->toBe(FindingCode::StaleBaseline)
        ->and($result['stale'][0]->key)->toBe('GONE')
        ->and($result['stale'][0]->severity()->value)->toBe('info');
});

it('saves a sorted, value-free file and loads it back', function () {
    $path = sys_get_temp_dir().'/envlens-baseline-'.uniqid().'.json';
    $baseline = Baseline::fromFindings([undeclaredFinding('Z'), undeclaredFinding('A'), outsideFinding('B')]);

    $baseline->save($path);
    $json = (string) file_get_contents($path);
    unlink($path);

    expect(json_decode($json, true)['schema'])->toBe(1)
        ->and(array_column(json_decode($json, true)['findings'], 'key'))->toBe(['B', 'A', 'Z'])
        ->and($json)->toEndWith("\n");

    file_put_contents($path, $json);
    expect(Baseline::load($path)?->entries())->toBe($baseline->entries());
    unlink($path);
});

it('returns null when there is no file', function () {
    expect(Baseline::load(sys_get_temp_dir().'/envlens-no-such-'.uniqid().'.json'))->toBeNull();
});

it('rejects a corrupt file', function (string $contents) {
    $path = sys_get_temp_dir().'/envlens-baseline-'.uniqid().'.json';
    file_put_contents($path, $contents);

    try {
        Baseline::load($path);
        $this->fail('Expected InvalidBaseline');
    } catch (InvalidBaseline $e) {
        expect($e->getMessage())->toContain('envlens-baseline')->not->toContain("\n");
    } finally {
        unlink($path);
    }
})->with([
    'not json' => ['nope'],
    'merge conflict' => ["<<<<<<< HEAD\n{\"schema\":1,\"findings\":[]}\n=======\n{}\n>>>>>>> other\n"],
    'wrong shape' => ['{"schema":1}'],
    'bad entry' => ['{"schema":1,"findings":[{"key":"A"}]}'],
    'unknown code' => ['{"schema":1,"findings":[{"code":"nope","key":"A"}]}'],
    'code that cannot be accepted' => ['{"schema":1,"findings":[{"code":"example-secret","key":"A"}]}'],
]);

it('fails clearly when the file cannot be written', function () {
    Baseline::fromFindings([])->save(sys_get_temp_dir().'/envlens-no-such-dir-'.uniqid().'/envlens-baseline.json');
})->throws(RuntimeException::class, 'Could not write');

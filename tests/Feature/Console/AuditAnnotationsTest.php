<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

function annotated(array $options, int $exit = 0): string
{
    putenv('GITHUB_ACTIONS=true');
    expect(Artisan::call('env:audit', $options))->toBe($exit);

    return Artisan::output();
}

function annotationProject(): string
{
    $path = fixtureProject('stock-13');
    file_put_contents($path.'/config/partner.php', "<?php\n\nreturn [\n    's' => env('NEW_PARTNER_SECRET'),\n];\n");
    file_put_contents($path.'/.env.example', "\nDUP_KEY=1\nDUP_KEY=2\nORPHAN=1\n# STRIPE_SECRET=sk_live_marker\n", FILE_APPEND);

    return $path;
}

it('prints annotations with a project-relative file and line, errors first', function () {
    $output = annotated(['--ci' => true, '--path' => annotationProject()], 1);

    expect($output)->toContain('::error file=config/partner.php,line=4,title=undeclared-key::NEW_PARTNER_SECRET is read by config')
        ->toContain('title=example-secret::STRIPE_SECRET has a real-looking value')
        ->toMatch('/::error file=\.env\.example,line=\d+,title=example-secret/')
        ->toMatch('/::warning file=\.env\.example,line=\d+,title=duplicate-key::/');

    expect(strrpos($output, '::error '))->toBeLessThan(strpos($output, '::warning '));
});

it('prints no annotation for info findings', function () {
    expect(annotated(['--ci' => true, '--path' => annotationProject()], 1))->not->toContain('title=unused-key');
});

it('prints annotations on plain env:audit too', function () {
    $path = annotationProject();
    copy($path.'/.env.example', $path.'/.env');

    expect(annotated(['--path' => $path, '--fail-on' => 'none']))->toContain('title=duplicate-key');
});

it('never puts a value in an annotation', function () {
    expect(annotated(['--ci' => true, '--path' => annotationProject()], 1))->not->toContain('sk_live_marker');
});

it('prints no annotations outside GitHub Actions', function () {
    expect(Artisan::call('env:audit', ['--ci' => true, '--path' => annotationProject()]))->toBe(1);
    expect(Artisan::output())->not->toContain('::error');
});

it('keeps JSON clean', function () {
    $output = annotated(['--ci' => true, '--path' => annotationProject(), '--json' => true], 1);

    expect($output)->not->toContain('::error')->and(json_decode($output, true))->toBeArray();
});

it('leaves accepted findings out', function () {
    $path = annotationProject();
    Artisan::call('env:audit', ['--ci' => true, '--path' => $path, '--update-baseline' => true]);

    expect(annotated(['--ci' => true, '--path' => $path], 1))->not->toContain('title=undeclared-key');
});

it('prints an annotation with no file for a finding with no location', function () {
    $path = fixtureProject('stock-13');
    file_put_contents($path.'/config/bad.php', "<?php\nreturn [ not php\n");

    expect(annotated(['--ci' => true, '--path' => $path], 1))->toMatch('/::error title=scan-warning::/');
});

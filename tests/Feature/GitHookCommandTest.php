<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/soloboard-test-repo-'.uniqid();
    mkdir($this->tempDir.'/.git/hooks', 0755, true);
});

afterEach(function () {
    // Clean up temp directory
    if (is_dir($this->tempDir)) {
        File::deleteDirectory($this->tempDir);
    }
});

test('git-hook-install creates post-commit hook', function () {
    $this->artisan('soloboard:git-hook-install', [
        'path' => $this->tempDir,
        '--url' => 'http://soloboard.test',
        '--key' => 'test-api-key-123',
    ])
        ->expectsOutput('Git hook post-commit instalado com sucesso!')
        ->assertSuccessful();

    $hookPath = $this->tempDir.'/.git/hooks/post-commit';

    expect(file_exists($hookPath))->toBeTrue();
    expect(is_executable($hookPath))->toBeTrue();

    $content = file_get_contents($hookPath);
    expect($content)->toContain('SoloBoard post-commit hook');
    expect($content)->toContain('http://soloboard.test');
    expect($content)->toContain('test-api-key-123');
});

test('git-hook-install fails for non-git directory', function () {
    $nonGitDir = sys_get_temp_dir().'/not-a-repo-'.uniqid();
    mkdir($nonGitDir, 0755, true);

    $this->artisan('soloboard:git-hook-install', [
        'path' => $nonGitDir,
    ])
        ->expectsOutputToContain('Diretório Git não encontrado')
        ->assertFailed();

    rmdir($nonGitDir);
});

test('git-hook-install uses default url', function () {
    $this->artisan('soloboard:git-hook-install', [
        'path' => $this->tempDir,
    ])
        ->assertSuccessful();

    $content = file_get_contents($this->tempDir.'/.git/hooks/post-commit');
    expect($content)->toContain('http://regnt.test');
});

test('git-hook-remove removes soloboard hook', function () {
    // First install the hook
    $this->artisan('soloboard:git-hook-install', [
        'path' => $this->tempDir,
    ])->assertSuccessful();

    $hookPath = $this->tempDir.'/.git/hooks/post-commit';
    expect(file_exists($hookPath))->toBeTrue();

    // Now remove it
    $this->artisan('soloboard:git-hook-remove', [
        'path' => $this->tempDir,
    ])
        ->expectsOutput('Git hook post-commit removido com sucesso!')
        ->assertSuccessful();

    expect(file_exists($hookPath))->toBeFalse();
});

test('git-hook-remove warns when no hook exists', function () {
    $this->artisan('soloboard:git-hook-remove', [
        'path' => $this->tempDir,
    ])
        ->expectsOutput('Nenhum hook post-commit encontrado.')
        ->assertSuccessful();
});

test('git-hook-remove refuses to remove non-soloboard hook', function () {
    $hookPath = $this->tempDir.'/.git/hooks/post-commit';
    file_put_contents($hookPath, "#!/bin/bash\necho 'custom hook'");

    $this->artisan('soloboard:git-hook-remove', [
        'path' => $this->tempDir,
    ])
        ->expectsOutputToContain('não é do SoloBoard')
        ->assertFailed();

    // Hook should still exist
    expect(file_exists($hookPath))->toBeTrue();
});

test('hook script extracts SB task id from commit message', function () {
    $this->artisan('soloboard:git-hook-install', [
        'path' => $this->tempDir,
    ])->assertSuccessful();

    $content = file_get_contents($this->tempDir.'/.git/hooks/post-commit');

    // Verify the hook script contains the task ID extraction patterns
    expect($content)->toContain('[SB-');
    expect($content)->toContain('#SB-');
    expect($content)->toContain('log-commits');
});

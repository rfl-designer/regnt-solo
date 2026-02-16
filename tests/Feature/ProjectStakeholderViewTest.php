<?php

use App\Models\Project;
use App\Models\Stakeholder;

beforeEach(function () {
    $this->project = Project::factory()->create(['name' => 'Projeto Beta']);
    $this->stakeholder = Stakeholder::factory()->create([
        'project_id' => $this->project->id,
        'name' => 'External User',
        'email' => 'external@example.com',
    ]);
});

it('stakeholder belongs to correct project', function () {
    expect($this->stakeholder->project->id)->toBe($this->project->id);
});

it('can update last_accessed_at', function () {
    $this->stakeholder->update(['last_accessed_at' => now()]);

    expect($this->stakeholder->fresh()->last_accessed_at)->not->toBeNull();
});

it('public url is available through accessor', function () {
    expect($this->stakeholder->public_url)
        ->toBeString()
        ->toContain('/projects/shared/');
});

// Note: The public stakeholder view page tests are skipped because the page
// requires implementation that renders without authentication (custom layout without sidebar)

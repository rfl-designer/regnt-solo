<?php

use App\Mail\StakeholderAccessLink;
use App\Models\Project;
use App\Models\Stakeholder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->project = Project::factory()->create(['name' => 'Projeto Alpha']);
    $this->stakeholder = Stakeholder::factory()->create([
        'project_id' => $this->project->id,
        'name' => 'Carlos Silva',
        'email' => 'carlos@example.com',
    ]);
});

it('can send email with stakeholder access link', function () {
    Mail::to($this->stakeholder->email)->send(new StakeholderAccessLink($this->stakeholder));

    Mail::assertSent(StakeholderAccessLink::class, function ($mail) {
        return $mail->hasTo($this->stakeholder->email);
    });
});

it('email contains stakeholder name, project name and correct link', function () {
    $mailable = new StakeholderAccessLink($this->stakeholder);
    $html = $mailable->render();

    expect($html)
        ->toContain($this->stakeholder->name)
        ->toContain($this->stakeholder->project->name)
        ->toContain($this->stakeholder->access_token);
});

it('email is sent synchronously (without queue)', function () {
    Mail::to($this->stakeholder->email)->send(new StakeholderAccessLink($this->stakeholder));

    Mail::assertSent(StakeholderAccessLink::class);
    Mail::assertNothingQueued();
});

it('email has correct subject', function () {
    $mailable = new StakeholderAccessLink($this->stakeholder);
    $envelope = $mailable->envelope();

    expect($envelope->subject)->toContain('Acesso ao Projeto');
});

it('can resend access link email', function () {
    // Send email
    Mail::to($this->stakeholder->email)->send(new StakeholderAccessLink($this->stakeholder));

    // Clear and resend
    Mail::fake();
    Mail::to($this->stakeholder->email)->send(new StakeholderAccessLink($this->stakeholder));

    Mail::assertSent(StakeholderAccessLink::class, function ($mail) {
        return $mail->hasTo($this->stakeholder->email);
    });
});

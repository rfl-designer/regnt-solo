<?php

declare(strict_types=1);

use App\Enums\ActivityStatus;
use App\Enums\ServiceClass;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

describe('Sidebar Organization', function (): void {
    it('displays sidebar with categorized groups', function (): void {
        $response = $this->get(route('dashboard'));

        $response->assertSuccessful();

        // Verifica os grupos da sidebar
        $response->assertSee('Planejamento');
        $response->assertSee('Acompanhamento');
        $response->assertSee('Análise');
    });

    it('shows "Semana X" format in weekly badge', function (): void {
        $response = $this->get(route('dashboard'));

        $response->assertSuccessful();

        // Verifica que o badge de semana usa formato legível
        $weekNumber = now()->weekOfYear;
        $response->assertSee("Semana {$weekNumber}");
    });
});

describe('Color Legend Component', function (): void {
    it('renders color legend on dashboard', function (): void {
        $response = $this->get(route('dashboard'));

        $response->assertSuccessful();
        $response->assertSee('Legenda');
    });

    it('renders color legend on kanban', function (): void {
        $response = $this->get(route('kanban'));

        $response->assertSuccessful();
        $response->assertSee('Legenda');
    });

    it('displays all status labels in legend', function (): void {
        $response = $this->get(route('dashboard'));

        $response->assertSuccessful();

        // Verifica que todas as labels de status estão disponíveis
        foreach (ActivityStatus::cases() as $status) {
            $response->assertSee($status->label());
        }
    });

    it('displays all service class labels in legend', function (): void {
        $response = $this->get(route('dashboard'));

        $response->assertSuccessful();

        // Verifica que todas as labels de classe de serviço estão disponíveis
        foreach (ServiceClass::cases() as $serviceClass) {
            $response->assertSee($serviceClass->label());
        }
    });
});

describe('Enum Color Methods', function (): void {
    it('returns valid color for each task status', function (ActivityStatus $status): void {
        $color = $status->color();

        expect($color)->toBeString()
            ->and($color)->not->toBeEmpty();
    })->with(ActivityStatus::cases());

    it('returns valid hex color for each task status', function (ActivityStatus $status): void {
        $hexColor = $status->hexColor();

        expect($hexColor)->toBeString()
            ->and($hexColor)->toStartWith('#')
            ->and(strlen($hexColor))->toBe(7);
    })->with(ActivityStatus::cases());

    it('returns valid icon for each task status', function (ActivityStatus $status): void {
        $icon = $status->icon();

        expect($icon)->toBeString()
            ->and($icon)->not->toBeEmpty();
    })->with(ActivityStatus::cases());

    it('returns valid color for each service class', function (ServiceClass $serviceClass): void {
        $color = $serviceClass->color();

        expect($color)->toBeString()
            ->and($color)->not->toBeEmpty();
    })->with(ServiceClass::cases());

    it('returns valid icon for each service class', function (ServiceClass $serviceClass): void {
        $icon = $serviceClass->icon();

        expect($icon)->toBeString()
            ->and($icon)->not->toBeEmpty();
    })->with(ServiceClass::cases());
});

<?php

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    #[Validate('required|in:DELETE', message: 'Digite DELETE para confirmar')]
    public string $confirmationText = '';

    /**
     * Check if the confirmation text matches.
     */
    public function getCanDeleteProperty(): bool
    {
        return $this->confirmationText === 'DELETE';
    }

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
            'confirmationText' => 'required|in:DELETE',
        ]);

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<section class="mt-10">
    {{-- Danger Zone Accordion --}}
    <flux:accordion transition>
        <flux:accordion.item>
            <flux:accordion.heading>
                <div class="flex items-center gap-2 text-red-400">
                    <flux:icon name="exclamation-triangle" variant="outline" class="size-5" />
                    <span>{{ __('Zona de Perigo') }}</span>
                </div>
            </flux:accordion.heading>

            <flux:accordion.content>
                <div class="space-y-6 pt-4">
                    {{-- Warning Section --}}
                    <div class="rounded-lg border border-red-500/20 bg-red-500/5 p-4">
                        <div class="flex gap-3">
                            <flux:icon name="exclamation-circle" class="size-5 shrink-0 text-red-400" />
                            <div class="space-y-2">
                                <p class="text-sm font-medium text-red-400">
                                    {{ __('Excluir sua conta é uma ação permanente e irreversível.') }}
                                </p>
                                <p class="text-sm text-zinc-400">
                                    {{ __('Ao excluir sua conta, você perderá acesso a:') }}
                                </p>
                                <ul class="list-inside list-disc space-y-1 text-sm text-zinc-400">
                                    <li>{{ __('Todos os seus projetos e configurações') }}</li>
                                    <li>{{ __('Todas as tasks e histórico de status') }}</li>
                                    <li>{{ __('Todos os registros de tempo (time entries)') }}</li>
                                    <li>{{ __('Planos diários e revisões semanais') }}</li>
                                    <li>{{ __('Templates e tasks recorrentes') }}</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    {{-- Delete Button Trigger --}}
                    <flux:modal.trigger name="confirm-user-deletion">
                        <flux:button
                            variant="ghost"
                            class="text-red-400 hover:bg-red-500/10 hover:text-red-300"
                            icon="trash"
                            x-data=""
                            x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')"
                            data-test="delete-user-button"
                        >
                            {{ __('Excluir minha conta') }}
                        </flux:button>
                    </flux:modal.trigger>
                </div>
            </flux:accordion.content>
        </flux:accordion.item>
    </flux:accordion>

    {{-- Confirmation Modal --}}
    <flux:modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
        <form method="POST" wire:submit="deleteUser" class="space-y-6">
            <div>
                <flux:heading size="lg" class="text-red-400">
                    <div class="flex items-center gap-2">
                        <flux:icon name="exclamation-triangle" class="size-6" />
                        {{ __('Tem certeza que deseja excluir sua conta?') }}
                    </div>
                </flux:heading>

                <flux:subheading class="mt-3">
                    {{ __('Esta ação não pode ser desfeita. Todos os seus dados serão permanentemente removidos dos nossos servidores.') }}
                </flux:subheading>
            </div>

            {{-- Two-step Confirmation --}}
            <div class="space-y-4">
                <flux:input
                    wire:model.live="confirmationText"
                    label="{{ __('Digite DELETE para confirmar') }}"
                    placeholder="DELETE"
                    autocomplete="off"
                    data-test="delete-confirmation-input"
                />

                <flux:input
                    wire:model="password"
                    :label="__('Sua senha')"
                    type="password"
                    data-test="delete-password-input"
                />
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancelar') }}</flux:button>
                </flux:modal.close>

                <flux:button
                    variant="danger"
                    type="submit"
                    :disabled="!$this->canDelete"
                    data-test="confirm-delete-user-button"
                >
                    {{ __('Excluir conta permanentemente') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>

<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateSoloUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'soloboard:create-user
                            {--name= : Nome do usuário}
                            {--email= : Email do usuário (default: SOLO_USER_EMAIL)}
                            {--password= : Senha do usuário (default: SOLO_USER_PASSWORD)}
                            {--force : Recria o usuário se já existir}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cria o usuário admin do SoloBoard';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->option('email') ?: config('solo.user_email');
        $password = $this->option('password') ?: config('solo.user_password');
        $name = $this->option('name') ?: 'Solo Developer';

        $existingUser = User::where('email', $email)->first();

        if ($existingUser && ! $this->option('force')) {
            $this->warn("Usuário com email [{$email}] já existe.");
            $this->info('Use --force para recriar o usuário.');

            return self::SUCCESS;
        }

        if ($existingUser && $this->option('force')) {
            $existingUser->delete();
            $this->info('Usuário existente removido.');
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $this->info('Usuário criado com sucesso!');
        $this->table(
            ['Campo', 'Valor'],
            [
                ['Nome', $name],
                ['Email', $email],
                ['Senha', str_repeat('*', strlen($password))],
            ]
        );

        return self::SUCCESS;
    }
}

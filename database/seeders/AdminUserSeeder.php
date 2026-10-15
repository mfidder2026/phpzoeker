<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('phpzoeker.admin.email');

        if (User::query()->where('email', $email)->exists()) {
            return;
        }

        $password = config('phpzoeker.admin.password');
        $generated = blank($password);
        $password = $generated ? Str::password(20) : (string) $password;

        $user = User::query()->create([
            'name' => (string) config('phpzoeker.admin.name'),
            'email' => $email,
            'password' => $password,
        ]);

        $user->assignRole(Role::Beheerder->value, Role::Accountmanager->value);

        if ($generated && $this->command !== null) {
            $this->command->warn("Beheerder {$email} aangemaakt met een willekeurig wachtwoord: {$password}");
            $this->command->warn('Wijzig dit na de eerste keer inloggen.');
        }
    }
}

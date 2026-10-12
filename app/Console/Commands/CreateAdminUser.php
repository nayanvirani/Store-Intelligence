<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Creates (or resets) an Internal Admin account and prints a one-time password. On deploy, the first
 * super admin can come from Railway variables (SI_ADMIN_EMAIL, SI_ADMIN_PASSWORD) with --only-if-none.
 */
class CreateAdminUser extends Command
{
    protected $signature = 'si:admin-user {email} {--name=Store Intelligence team} {--password-from-env : Use SI_ADMIN_PASSWORD} {--only-if-none : Do nothing when an admin already exists}';

    protected $description = 'Create or reset an Internal Admin account';

    public function handle(): int
    {
        if ($this->option('only-if-none') && User::where('is_admin', true)->exists()) {
            $this->line('An admin already exists; nothing changed.');

            return self::SUCCESS;
        }
        $email = strtolower(trim($this->argument('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('That isn\'t a valid email address.');

            return self::FAILURE;
        }
        $fromEnv = $this->option('password-from-env') ? (string) env('SI_ADMIN_PASSWORD', '') : '';
        if ($this->option('password-from-env') && mb_strlen($fromEnv) < 12) {
            $this->error('SI_ADMIN_PASSWORD must be at least 12 characters.');

            return self::FAILURE;
        }
        $password = $fromEnv ?: Str::password(20, symbols: false);
        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill(['name' => $user->name ?: $this->option('name'), 'password' => $password, 'is_admin' => true, 'admin_role' => 'super_admin', 'disabled_at' => null])->save();
        $this->info(($user->wasRecentlyCreated ? 'Created' : 'Reset').' admin '.$email);
        if (! $fromEnv) {
            $this->line('Password (shown once): '.$password);
        }
        $this->line('Sign in at '.route('admin.login'));

        return self::SUCCESS;
    }
}

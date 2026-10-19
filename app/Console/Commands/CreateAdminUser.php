<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Creates (or repairs) the first admin account on a fresh deployment.
 *
 * A brand-new production database has no users, and every route worth
 * reaching is behind auth, so there is otherwise no way in without a shell.
 * This runs from the container entrypoint and is idempotent: it only acts
 * when the bootstrap credentials are configured, and re-running it simply
 * re-asserts the role, verification and password.
 *
 * Credentials come from config (not env() directly) so this still works
 * after `php artisan config:cache`.
 */
class CreateAdminUser extends Command
{
    protected $signature = 'helpdesk:create-admin
                            {--email= : Override the configured email}
                            {--password= : Override the configured password}';

    protected $description = 'Create or repair the bootstrap admin account';

    public function handle(): int
    {
        $email = $this->option('email') ?: config('helpdesk.bootstrap_admin.email');
        $password = $this->option('password') ?: config('helpdesk.bootstrap_admin.password');

        if (blank($email) || blank($password)) {
            $this->line('helpdesk:create-admin - no bootstrap credentials configured, skipping.');

            return self::SUCCESS;
        }

        $email = mb_strtolower(trim($email));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("helpdesk:create-admin - '{$email}' is not a valid email address.");

            return self::FAILURE;
        }

        if (mb_strlen($password) < 8) {
            $this->error('helpdesk:create-admin - password must be at least 8 characters.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->forceFill([
                'password' => Hash::make($password),
                'role' => 'admin',
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            $this->info("helpdesk:create-admin - updated existing user {$email} (role: admin).");

            return self::SUCCESS;
        }

        User::forceCreate([
            'name' => 'Administrator',
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'admin',
            // Pre-verified: the app uses MustVerifyEmail, and on a fresh deploy
            // there may be no working mailer to deliver a verification link.
            'email_verified_at' => now(),
        ]);

        $this->info("helpdesk:create-admin - created admin {$email}.");
        $this->warn('Change this password after signing in, then clear HELPDESK_ADMIN_PASSWORD.');

        return self::SUCCESS;
    }
}

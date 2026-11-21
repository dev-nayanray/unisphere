<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Warn (in the log) about N+1 lazy loading while developing, without breaking requests.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) {
            logger()->warning('Lazy loaded ['.$relation.'] on model ['.$model::class.'].');
        });

        // Super admins pass every check, except actions that only make sense
        // for the person themselves (submitting work, paying, checking in...).
        Gate::before(function (User $user, string $ability) {
            $personal = ['submit', 'take', 'attend', 'pay', 'drop'];

            return $user->hasRole(Role::SuperAdmin) && ! in_array($ability, $personal, true) ? true : null;
        });

        // Password reset emails link to the React app, not to a Laravel view.
        ResetPassword::createUrlUsing(fn (User $user, string $token) => config('unisphere.frontend_url')
            .'/reset-password?token='.$token.'&email='.urlencode($user->email));
    }
}

<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Model;
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

        // Password reset emails link to the React app, not to a Laravel view.
        ResetPassword::createUrlUsing(fn (User $user, string $token) => config('unisphere.frontend_url')
            .'/reset-password?token='.$token.'&email='.urlencode($user->email));
    }
}

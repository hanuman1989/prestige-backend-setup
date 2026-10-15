<?php

namespace App\Providers;

use App\Models\AdminUser;
use App\Models\Car;
use App\Models\CarCategory;
use App\Models\Category;
use App\Models\Destination;
use App\Models\Package;
use App\Models\PackageImage;
use App\Models\PackageItinerary;
use App\Observers\CarCategoryObserver;
use App\Observers\CarObserver;
use App\Observers\CategoryObserver;
use App\Observers\DestinationObserver;
use App\Observers\PackageObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CategoryObserver::class);
        $this->app->singleton(CarCategoryObserver::class);
        $this->app->singleton(CarObserver::class);
        $this->app->singleton(DestinationObserver::class);
        $this->app->singleton(PackageObserver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('manage-admin-users', fn (Authenticatable $user): bool => $user instanceof AdminUser && $user->status);
        Gate::define('manage-categories', fn (Authenticatable $user): bool => $user instanceof AdminUser && $user->status);
        Gate::define('manage-car-categories', fn (Authenticatable $user): bool => $user instanceof AdminUser && $user->status);
        Gate::define('manage-cars', fn (Authenticatable $user): bool => $user instanceof AdminUser && $user->status);
        Gate::define('manage-destinations', fn (Authenticatable $user): bool => $user instanceof AdminUser && $user->status);
        Gate::define('manage-packages', fn (Authenticatable $user): bool => $user instanceof AdminUser && $user->status);

        Category::observe(CategoryObserver::class);
        Event::listen(TransactionCommitted::class, [CategoryObserver::class, 'committed']);
        Event::listen(TransactionRolledBack::class, [CategoryObserver::class, 'rolledBack']);

        CarCategory::observe(CarCategoryObserver::class);
        Event::listen(TransactionCommitted::class, [CarCategoryObserver::class, 'committed']);
        Event::listen(TransactionRolledBack::class, [CarCategoryObserver::class, 'rolledBack']);

        Car::observe(CarObserver::class);
        Event::listen(TransactionCommitted::class, [CarObserver::class, 'committed']);
        Event::listen(TransactionRolledBack::class, [CarObserver::class, 'rolledBack']);

        Destination::observe(DestinationObserver::class);
        Event::listen(TransactionCommitted::class, [DestinationObserver::class, 'committed']);
        Event::listen(TransactionRolledBack::class, [DestinationObserver::class, 'rolledBack']);

        Package::observe(PackageObserver::class);
        PackageImage::observe(PackageObserver::class);
        PackageItinerary::observe(PackageObserver::class);
        Event::listen(TransactionCommitted::class, [PackageObserver::class, 'committed']);
        Event::listen(TransactionRolledBack::class, [PackageObserver::class, 'rolledBack']);

        RateLimiter::for('login', function (Request $request): array {
            $email = $request->input('email');
            $email = is_string($email) ? Str::lower($email) : '';

            return [
                Limit::perMinute(30)->by('login-ip:'.$request->ip()),
                Limit::perMinute(5)->by($request->path().'|'.$email.'|'.$request->ip()),
            ];
        });
    }
}

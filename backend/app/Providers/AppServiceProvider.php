<?php

namespace App\Providers;

use App\Models\Ticket;
use App\Policies\TicketPolicy;
use App\Repositories\Contracts\TaxonomyRepositoryInterface;
use App\Repositories\Contracts\TicketRepositoryInterface;
use App\Repositories\Eloquent\CachedTaxonomyRepository;
use App\Repositories\Eloquent\EloquentTicketRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Pattern Repository: la lógica de negocio depende de las interfaces, no de Eloquent.
        $this->app->bind(TicketRepositoryInterface::class, EloquentTicketRepository::class);
        $this->app->singleton(TaxonomyRepositoryInterface::class, CachedTaxonomyRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        Gate::policy(Ticket::class, TicketPolicy::class);
    }
}

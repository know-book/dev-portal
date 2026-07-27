<?php

namespace App\Providers;

use App\Contracts\ArgoApplicationGateway;
use App\Contracts\GitOpsRepositoryPublisher;
use App\Contracts\ProjectSecretStore;
use App\Services\ArgoCd\ArgoApplicationService;
use App\Services\GitHub\GitHubGitOpsRepositoryPublisher;
use App\Services\Vault\VaultKvV2ProjectSecretStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ArgoApplicationGateway::class, ArgoApplicationService::class);
        $this->app->bind(GitOpsRepositoryPublisher::class, GitHubGitOpsRepositoryPublisher::class);
        $this->app->bind(ProjectSecretStore::class, VaultKvV2ProjectSecretStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        if ($this->app->environment('production')) {
            \URL::forceHttps();
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}

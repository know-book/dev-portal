<?php

use App\Models\GitHubInstallation;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('GitHub Integration')] class extends Component {
    public function disconnectInstallation(int $installationId): void
    {
        $installation = Auth::user()->currentTeam->githubInstallations()->findOrFail($installationId);
        $accountName = $installation->account_name;

        $installation->delete();

        Flux::toast(variant: 'success', text: __('Disconnected GitHub installation for ":name".', ['name' => $accountName]));
    }

    /**
     * @return Collection<int, GitHubInstallation>
     */
    #[Computed]
    public function installations(): Collection
    {
        return Auth::user()->currentTeam?->githubInstallations()->get() ?? collect();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('GitHub App Integration') }}</flux:heading>

    <x-pages::settings.layout :heading="__('GitHub App Integration')" :subheading="__('Connect GitHub Accounts or Organizations to deploy repositories to Kubernetes.')">
        <div class="flex items-center justify-between border-b border-slate-200 pb-4 dark:border-slate-800">
            <div>
                <flux:heading size="md" class="font-semibold">{{ __('GitHub App Connections') }}</flux:heading>
                <flux:text class="text-xs text-slate-500">{{ __('Install the Dev Portal GitHub App on your GitHub account or org.') }}</flux:text>
            </div>

            @php
                $appSlug = config('services.github.app_slug', 'dev-portal-app');
                $installUrl = "https://github.com/apps/{$appSlug}/installations/new";
            @endphp

            <flux:button variant="primary" icon="plus" href="{{ $installUrl }}" target="_blank" class="cursor-pointer bg-blue-600 hover:bg-blue-700">
                {{ __('Install GitHub App') }}
            </flux:button>
        </div>

        <div class="mt-6 space-y-3">
            @forelse ($this->installations as $installation)
                <div class="flex items-center justify-between rounded-md border border-slate-200 bg-white p-4 shadow-2xs dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center gap-3">
                        @if ($installation->account_avatar_url)
                            <img src="{{ $installation->account_avatar_url }}" alt="{{ $installation->account_name }}" class="size-9 rounded-full" />
                        @else
                            <div class="flex size-9 items-center justify-center rounded-full bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <flux:icon name="folder-git-2" class="size-5" />
                            </div>
                        @endif

                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-slate-900 dark:text-slate-100">{{ $installation->account_name }}</span>
                                <flux:badge size="sm" color="blue" class="font-mono text-2xs uppercase">{{ $installation->account_type }}</flux:badge>
                            </div>
                            <flux:text class="font-mono text-xs text-slate-400">ID: {{ $installation->installation_id }}</flux:text>
                        </div>
                    </div>

                    <flux:button
                        variant="ghost"
                        size="sm"
                        icon="trash"
                        wire:click="disconnectInstallation({{ $installation->id }})"
                        class="cursor-pointer text-red-600 hover:text-red-700 dark:text-red-400"
                        :tooltip="__('Disconnect GitHub App')"
                    />
                </div>
            @empty
                <div class="flex flex-col items-center justify-center rounded-md border border-dashed border-slate-300 p-8 text-center dark:border-slate-800">
                    <flux:icon name="folder-git-2" class="size-10 text-slate-400" />
                    <flux:heading size="md" class="mt-3">{{ __('No GitHub App Connected') }}</flux:heading>
                    <flux:text class="mt-1 text-xs text-slate-500">{{ __('Connect your GitHub account to enable automated GitOps deployments & PR previews.') }}</flux:text>
                    <flux:button variant="primary" icon="plus" href="{{ $installUrl }}" target="_blank" class="mt-4 cursor-pointer bg-blue-600">
                        {{ __('Connect GitHub Account') }}
                    </flux:button>
                </div>
            @endforelse
        </div>
    </x-pages::settings.layout>
</section>

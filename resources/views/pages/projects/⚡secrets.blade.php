<?php

use App\Actions\Projects\GetProjectSecretMetadata;
use App\Actions\Projects\ReadProjectSecrets;
use App\Actions\Projects\UpdateProjectSecrets;
use App\Exceptions\SecretStoreException;
use App\Exceptions\SecretVersionConflict;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Project Secrets')] class extends Component {
    #[Locked]
    public Project $project;

    public string $secretJson = "{\n}\n";

    public int $secretVersion = 0;

    public int $secretCount = 0;

    public bool $revealed = false;

    public ?bool $secretExists = null;

    public function mount(Project $project, GetProjectSecretMetadata $getMetadata): void
    {
        $team = Auth::user()->currentTeam;

        abort_if($project->team_id !== $team->id, 404);

        $this->project = $project->loadMissing('team');

        try {
            $metadata = $getMetadata->handle($this->project, Auth::user());
        } catch (SecretStoreException $exception) {
            $this->addError('secretJson', $exception->getMessage());

            return;
        }

        $this->secretExists = $metadata->exists;

        if (! $metadata->exists) {
            $this->revealed = true;
        }
    }

    public function reveal(ReadProjectSecrets $readSecrets): void
    {
        $this->resetErrorBag();

        try {
            $document = $readSecrets->handle($this->project, Auth::user());
            $this->secretJson = $this->formatJson($document->values);
        } catch (SecretStoreException|\JsonException $exception) {
            $this->addError('secretJson', $exception instanceof SecretStoreException
                ? $exception->getMessage()
                : __('The secret document could not be displayed.'));

            return;
        }

        $this->secretVersion = $document->version;
        $this->secretCount = count($document->values);
        $this->secretExists = $document->version > 0;
        $this->revealed = true;
    }

    public function hideSecrets(): void
    {
        $this->secretJson = "{\n}\n";
        $this->secretVersion = 0;
        $this->secretCount = 0;
        $this->revealed = false;
        $this->resetErrorBag();
    }

    public function save(UpdateProjectSecrets $updateSecrets): void
    {
        $this->resetErrorBag();

        if (! $this->revealed) {
            $this->addError('secretJson', __('Reveal the current secret before editing it.'));

            return;
        }

        $this->validate([
            'secretJson' => ['required', 'string', 'max:200000'],
        ]);

        $values = $this->parseJson();

        try {
            $document = $updateSecrets->handle(
                $this->project,
                Auth::user(),
                $values,
                $this->secretVersion,
            );
        } catch (SecretVersionConflict $exception) {
            $this->addError('secretJson', $exception->getMessage());

            return;
        } catch (SecretStoreException $exception) {
            $this->addError('secretJson', $exception->getMessage());

            return;
        }

        $this->secretJson = $this->formatJson($document->values);
        $this->secretVersion = $document->version;
        $this->secretCount = count($document->values);
        $this->secretExists = true;

        Flux::toast(variant: 'success', text: __('Vault secret version :version saved.', ['version' => $document->version]));
    }

    /** @return array<string, string> */
    protected function parseJson(): array
    {
        try {
            $decoded = json_decode($this->secretJson, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages([
                'secretJson' => __('Enter a valid JSON object.'),
            ]);
        }

        if (! $decoded instanceof \stdClass) {
            throw ValidationException::withMessages([
                'secretJson' => __('The top-level JSON value must be an object.'),
            ]);
        }

        $values = get_object_vars($decoded);

        if (count($values) > 200) {
            throw ValidationException::withMessages([
                'secretJson' => __('A project secret may contain at most 200 variables.'),
            ]);
        }

        foreach ($values as $key => $value) {
            if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $key) !== 1 || ! is_string($value)) {
                throw ValidationException::withMessages([
                    'secretJson' => __('Use environment variable names as keys and strings as values. Nested JSON is not supported.'),
                ]);
            }
        }

        /** @var array<string, string> $values */
        ksort($values);

        return $values;
    }

    /**
     * @param  array<string, string>  $values
     *
     * @throws \JsonException
     */
    protected function formatJson(array $values): string
    {
        return json_encode((object) $values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-4 border-b border-slate-200 pb-5 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
        <div class="flex items-center gap-3">
            <a href="{{ route('projects.show', ['project' => $project->slug]) }}" wire:navigate class="cursor-pointer rounded-md p-2 hover:bg-slate-100 dark:hover:bg-slate-800">
                <flux:icon name="arrow-left" class="size-5 text-slate-500" />
            </a>

            <div>
                <div class="flex items-center gap-2">
                    <flux:heading size="xl" class="font-semibold text-slate-900 dark:text-slate-100">{{ __('Environment & Secrets') }}</flux:heading>
                    <flux:badge color="amber" size="sm" class="font-mono text-2xs uppercase">Vault KV v2</flux:badge>
                </div>
                <flux:subheading class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $project->name }}</flux:subheading>
            </div>
        </div>

        <div class="flex items-center gap-2">
            @if ($revealed)
                @if ($secretExists)
                    <flux:button variant="filled" icon="eye-slash" wire:click="hideSecrets" class="cursor-pointer">
                        {{ __('Hide') }}
                    </flux:button>
                @endif
                <flux:button variant="primary" icon="cloud-arrow-up" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="cursor-pointer bg-blue-600 hover:bg-blue-700 dark:bg-blue-500">
                    <span wire:loading.remove wire:target="save">{{ $secretExists ? __('Save to Vault') : __('Create in Vault') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving...') }}</span>
                </flux:button>
            @else
                <flux:button variant="primary" icon="eye" wire:click="reveal" wire:loading.attr="disabled" wire:target="reveal" class="cursor-pointer bg-blue-600 hover:bg-blue-700 dark:bg-blue-500">
                    <span wire:loading.remove wire:target="reveal">{{ __('Reveal from Vault') }}</span>
                    <span wire:loading wire:target="reveal">{{ __('Loading...') }}</span>
                </flux:button>
            @endif
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="rounded-md border border-slate-200 bg-white shadow-2xs dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-4 dark:border-slate-800">
                <div>
                    <flux:heading size="sm">{{ __('JSON editor') }}</flux:heading>
                    <flux:subheading class="text-xs">{{ __('Environment variables are secrets. Values are never written to Git or the Dev Portal database.') }}</flux:subheading>
                </div>
                @if ($revealed)
                    <div class="flex items-center gap-2">
                        <flux:badge color="zinc" size="sm" class="font-mono">{{ $secretCount }} {{ __('keys') }}</flux:badge>
                        @if ($secretExists)
                            <flux:badge color="blue" size="sm" class="font-mono">v{{ $secretVersion }}</flux:badge>
                        @else
                            <flux:badge color="amber" size="sm" class="font-mono uppercase">{{ __('New') }}</flux:badge>
                        @endif
                    </div>
                @endif
            </div>

            <div class="p-5">
                @if ($revealed)
                    @if (! $secretExists)
                        <flux:callout variant="warning" heading="{{ __('New Vault secret') }}" class="mb-4">
                            <flux:text>{{ __('This Vault path does not exist yet. Enter a flat JSON object, then select Create in Vault.') }}</flux:text>
                        </flux:callout>
                    @endif
                    <flux:textarea
                        wire:model="secretJson"
                        rows="28"
                        class="font-mono text-xs leading-5"
                        spellcheck="false"
                        autocomplete="off"
                        data-test="secret-json-editor"
                    />
                @else
                    <div class="flex min-h-[34rem] flex-col items-center justify-center rounded-md border border-dashed border-slate-300 bg-slate-50 px-6 text-center dark:border-slate-700 dark:bg-slate-800/50">
                        <flux:icon name="lock-closed" class="size-10 text-slate-400" />
                        <flux:heading size="sm" class="mt-4">{{ __('Secret values are hidden') }}</flux:heading>
                        <flux:text class="mt-2 max-w-md text-xs text-slate-500">{{ __('Reveal loads the current version directly from Vault. Nothing is loaded when this page opens.') }}</flux:text>
                    </div>
                @endif

                <flux:error name="secretJson" />
            </div>
        </div>

        <div class="space-y-4">
            <div class="rounded-md border border-slate-200 bg-white p-5 shadow-2xs dark:border-slate-800 dark:bg-slate-900">
                <flux:heading size="sm">{{ __('Vault path') }}</flux:heading>
                <div class="mt-3 break-all rounded bg-slate-50 px-3 py-2 font-mono text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    {{ config('services.vault.mount', 'secret') }}/{{ $project->team->slug }}/{{ $project->slug }}/app
                </div>
                <flux:text class="mt-3 text-xs text-slate-500">{{ __('Only a flat JSON object is accepted. Keys must be valid environment variable names and every value must be a string.') }}</flux:text>
            </div>

            <flux:callout icon="shield-exclamation" heading="{{ __('Optimistic locking enabled') }}">
                <flux:text>{{ __('Saving uses Vault CAS. If another person changes the secret first, your save is rejected instead of overwriting their work.') }}</flux:text>
            </flux:callout>

            <flux:callout variant="warning" icon="arrow-path" heading="{{ __('Restart may be required') }}">
                <flux:text>{{ __('Running workloads do not automatically reload environment variables. Restart or roll out the workload after saving.') }}</flux:text>
            </flux:callout>
        </div>
    </div>
</div>

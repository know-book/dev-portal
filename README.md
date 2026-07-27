# Dev Portal

An open-source, self-hosted Kubernetes-native platform (Vercel alternative) designed to simplify containerized application deployment, GitOps sync, and secret management without Kubernetes complexity.

## Key Features

- **Framework Presets**: First-class support for Laravel 13 (PHP 8.4) and Next.js (Node.js) applications with automated container build and runtime configurations.
- **GitOps Automation**: Automated application sync, health status tracking, and rollback policies powered by ArgoCD.
- **Secret Management**: Raw JSON environment/secret editor backed directly by HashiCorp Vault KV v2 with CAS conflict protection.
- **Guided Deployment**: A state-aware workflow prepares and publishes manifests, verifies Vault, reconciles and syncs Argo CD, then verifies ExternalSecret readiness.
- **No-Code Manifest Builder**: Pure Web UI for managing Kubernetes resources, custom domains, Ingress, and autoscaling (HPA).
- **Multi-Tenancy & RBAC**: Organization and Team workspace isolation backed by Kubernetes Namespace policies.
- **VMware Clarity Design**: Clean, crisp enterprise UI built with Livewire 4, Flux UI, and Tailwind CSS v4.

## Tech Stack

| Component | Technology |
| --- | --- |
| Backend | Laravel 13 (PHP 8.4) |
| Frontend | Livewire 4, Flux UI, Tailwind CSS v4 |
| Authentication | Laravel Fortify (Passkeys, 2FA, OAuth) |
| GitOps Engine | ArgoCD REST API / Custom Resources |
| Secret Engine | HashiCorp Vault KV v2 / External Secrets Operator |
| Testing Suite | Pest PHP |

## Getting Started

### Prerequisites

- PHP 8.4+
- Composer 2.x
- Node.js 20+ & npm
- PostgreSQL or MySQL
- Kubernetes cluster with ArgoCD (optional for local dev)

### Local Development Setup

1. **Clone the repository:**
   ```bash
   git clone https://github.com/your-org/dev-portal.git
   cd dev-portal
   ```

2. **Install dependencies:**
   ```bash
   composer install
   npm install
   ```

3. **Configure Environment:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

   GitOps publishing requires a GitHub App installation with `Contents: Read and write` access. Configure Vault, Kubernetes, and Argo CD using the `VAULT_*`, `KUBERNETES_*`, and `ARGOCD_*` variables documented in `.env.example`.

4. **Run Migrations and Seeders:**
   ```bash
   php artisan migrate --seed
   ```
   *Default login credentials:*
   - **Email:** `admin@admin.com`
   - **Password:** `password`

5. **Start Development Server:**
   ```bash
   composer run dev
   ```

## Testing & Code Formatting

Run the Pest test suite:
```bash
php artisan test
```

Format PHP code with Laravel Pint:
```bash
vendor/bin/pint
```

## Architecture & Roadmap

### Control-plane permissions

- Vault is the only source of truth for environment variables. The configured token needs KV v2 read/create/update access under `<mount>/data/<team>/<project>/app` and read access under the matching `<mount>/metadata/<team>/<project>/app` path; secret values are never stored in the Dev Portal database or Git.
- The Kubernetes credential needs `create` and `patch` on `applications.argoproj.io` in the configured Argo CD namespace, plus `get` and `patch` on `externalsecrets.external-secrets.io` in project namespaces. Application reconciliation uses Server-Side Apply with field manager `dev-portal`; ExternalSecret refresh patches only the `force-sync` annotation.
- The Argo CD bearer token needs scoped `applications, get` and `applications, sync` permissions for the configured Argo project. Do not use the admin token.
- Argo CD must have its own credentials for private Git repositories. Dev Portal does not copy GitHub App credentials into Argo CD.

For detailed architectural guidelines, provider abstraction specs, and open-source roadmap, please refer to [PLAN.md](PLAN.md) and [AGENTS.md](AGENTS.md).

## License

This project is open-source software licensed under the [MIT License](LICENSE).

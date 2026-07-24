# Dev Portal

An open-source, self-hosted Kubernetes-native platform (Vercel alternative) designed to simplify containerized application deployment, GitOps sync, and secret management without Kubernetes complexity.

## Key Features

- **Framework Presets**: First-class support for Laravel 13 (PHP 8.4) and Next.js (Node.js) applications with automated container build and runtime configurations.
- **GitOps Automation**: Automated application sync, health status tracking, and rollback policies powered by ArgoCD.
- **Secret Management**: Interactive `.env` key-value editor integrated directly with HashiCorp Vault (KV v2 engine) for secure secret injection.
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

For detailed architectural guidelines, provider abstraction specs, and open-source roadmap, please refer to [PLAN.md](PLAN.md) and [AGENTS.md](AGENTS.md).

## License

This project is open-source software licensed under the [MIT License](LICENSE).

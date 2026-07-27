# Open-Source Developer Portal (Kubernetes-Native Vercel Alternative)

## 📌 Project Vision & Mission

**Dev Portal** is an open-source, self-hosted Kubernetes-native PaaS designed to provide developer experience (DX) comparable to Vercel, Railway, and Render—directly on top of any Kubernetes cluster.

It eliminates the steep Kubernetes learning curve by offering a **pure UI-driven Manifest & Deployment Management System** backed by modern GitOps workflows (ArgoCD), HashiCorp Vault Secrets Engine, and automated Git provider integrations.

---

## 🚀 Core Features & Architectural Guiding Principles

### 1. Primary Framework Presets (First-Class DX)
Dedicated auto-detection, build configuration, and container templates tailored for:
- 🐘 **Laravel Applications**:
  - Auto-detection of PHP 8.x, Composer, extensions, and Artisan commands.
  - Native support for Web Server (Octane / Nginx + FPM), Background Queue Workers (`queue:work`), Scheduler (`schedule:run`), and Horizon.
  - Automated database migration hooks (`php artisan migrate --force`).
- ⚛️ **Next.js Applications**:
  - Auto-detection of Node.js, Package Managers (pnpm, yarn, npm, bun), and Next.js output modes (`standalone`, SSR, Static Export).
  - Optimized multi-stage Docker buildpacks for minimal container footprint.
  - Automated Edge / Serverless / Containerized deployment configuration.

### 2. HashiCorp Vault & Secret Management UI
- **Direct JSON Secret Editor**: Environment variables and secrets are one domain object, edited as a flat JSON object with string keys and string values. The UI does not maintain a separate `.env` or key-value representation.
- **HashiCorp Vault Integration**:
  - HashiCorp Vault KV v2 is the source of truth for project secret JSON.
  - Secret values are never committed to Git or stored in manifest revisions.
  - Automated injection into Kubernetes `Secrets` via External Secrets Operator (ESO).
  - Version-aware writes use Vault CAS to prevent concurrent edits from silently overwriting each other.

### 3. Modular Git Provider Integration (GitOps Engine)
- **GitHub App First-Class Support**: Seamless repo discovery, OAuth, permission handling, and webhook automation for Push, PR, and Tag/Release events.
- **Provider Abstraction Layer**: Built with extensible provider interfaces to easily support **GitLab**, **Bitbucket**, and self-hosted **Gitea/Forgejo** in future releases.
- **Dual GitOps Repository Modes**: Each project can keep deployment manifests in its source repository or publish them to a separate GitOps repository.
- **Repository Read/Write Access**: Both modes require the connected Git provider installation to have read and write access to every repository used by the project. Access is validated when the repository is connected and again before publishing manifests.
- **Safe Manifest Publishing**: Support direct commits for small projects and pull-request publishing for reviewed production workflows. Co-located repositories ignore portal-authored or manifest-only commits in build webhooks to prevent deployment commit loops.
- **Preview Deployments**: Automatic staging environment creation for every Pull Request / Branch with ephemeral URLs.

### 4. Native ArgoCD Engine Integration
- **Declarative GitOps Management**: Programmatically generate and reconcile ArgoCD `Application` Custom Resources (CRDs) through the Kubernetes API, with each Application source derived from the project's selected GitOps repository mode.
- **Operational API Integration**: Use the ArgoCD REST API for manual sync, hard refresh, resource trees, deployment history, and other operational actions.
- **Real-Time Health & Log Streaming**: Real-time deployment status, pod health monitoring, and live container logging streamed directly to the Web UI via WebSockets/Server-Sent Events (SSE).
- **Automated Rollback & Sync Policy**: UI-driven manual sync, auto-sync, prune policies, and instant rollbacks to previous git commit revisions.
- **Guided Deployment Workflow**: A state-aware stepper walks operators through manifest preparation, Git publication, Vault secret verification, Argo CD Application reconciliation, Argo CD sync, and ExternalSecret readiness verification. Completed steps are derived from persisted or provider state rather than browser-only progress.

### 5. Pure UI Manifest Builder (No-Code K8s Abstraction)
- **Zero-YAML Developer Experience**: Intuitive UI for configuring:
  - Framework runtime settings (Laravel workers/scheduler, Next.js standalone SSR).
  - Domain Routing & TLS (Ingress, Gateway API, Cert-Manager integration for free SSL).
  - Horizontal Pod Autoscaling (HPA), Replicas, and Resource Requests/Limits (CPU & Memory).
  - Persistent Volume Claims (PVC) & Managed Database Attachments.
- **Transparent Manifest Compilation**: Users can preview, export, or customize the generated Kubernetes Manifests (Helm Charts / Kustomize / Raw YAML) at any time to avoid vendor lock-in.

### 6. GitOps Repository Modes

Every project selects one of the following repository modes. The compiled manifest tree remains identical in both modes; only the publication target changes.

#### Co-Located Mode

- Application source code and generated deployment manifests are maintained in the same repository.
- The manifest path is configurable and defaults to `deploy/k8s`.
- The deployment branch defaults to the project's default branch.
- Source pushes may trigger image builds, but portal-authored commits and pushes that only change the configured manifest path must not trigger another build.
- ArgoCD points to the project's source repository, deployment branch, and manifest path.

#### Separate GitOps Repository Mode

- Application source code remains in the source repository while generated deployment manifests are published to a separately selected GitOps repository.
- GitOps repository, branch, and base path are configured per project.
- The Git provider installation must have read and write access to both the source repository and the GitOps repository.
- ArgoCD points to the separate GitOps repository and the project-specific path within it.

#### Shared Publication Contract

- Both modes support direct-commit and pull-request publishing strategies.
- Every successful publication records the target repository, branch, path, commit SHA, compiled hash, actor, and timestamp without recording secret values.
- Publication must be idempotent: an unchanged compiled hash does not create another commit.
- Repository access loss blocks publication with an actionable error and never falls back to another repository implicitly.
- Secret JSON remains in Vault; Git contains only `ExternalSecret` references and non-sensitive rollout metadata.

---

## 🛠 Tech Stack & Ecosystem

| Component | Technology |
| :--- | :--- |
| **Backend Framework** | Laravel 13 (PHP 8.4) |
| **Frontend UI** | Livewire 4 + Flux UI (Tailwind CSS v4) |
| **Primary Target Frameworks** | Laravel & Next.js |
| **Secret Engine** | HashiCorp Vault (KV v2) / External Secrets Operator |
| **Authentication** | Laravel Fortify (OAuth2, Passkeys, 2FA/TOTP) |
| **GitOps Engine** | ArgoCD (REST/gRPC & K8s Custom Resources) |
| **Build Engine Support** | Dockerfile, Nixpacks, Cloud Native Buildpacks |
| **Testing Suite** | Pest PHP (Unit, Feature, Component) |
| **Database** | PostgreSQL / MySQL |

---

## 🗺 Open-Source Roadmap

### Phase 1: MVP Core (Current Target)
- [ ] GitHub App integration (OAuth, Repo Listing, Webhook Receiver).
- [ ] Framework Presets for **Laravel** & **Next.js** (Container templates & build specs).
- [x] Flat JSON Environment/Secret Editor with **HashiCorp Vault KV v2** sync and CAS conflict protection.
- [ ] UI Manifest Builder (Deployment, Service, Ingress, Secret).
- [x] Co-located and separate GitOps repository modes with read/write permission validation and idempotent manifest publishing.
- [x] ArgoCD Application controller integration (Create, Sync, Status Check).
- [x] Guided deployment workflow with Vault metadata checks and ExternalSecret force-sync/readiness verification.

### Phase 2: Developer Experience & Multi-Tenancy
- [ ] Ephemeral Preview Deployments for Pull Requests.
- [ ] Multi-tenant Workspaces with RBAC (Roles & Permissions).
- [ ] Custom Domains & Cert-Manager integration for automatic HTTPS.
- [ ] Live Pod logs & Terminal (Web TTY) streaming.

### Phase 3: Ecosystem & Extensibility
- [ ] Additional Git Providers (GitLab, Gitea).
- [ ] One-Click Addon Marketplace (PostgreSQL, Redis, Meilisearch via Helm).
- [ ] Helm & Kustomize export/import options.
- [ ] CLI Tool (`devportal-cli`) for local terminal deployments.

---

## 🤝 Contributing & License
Designed from day one to be community-driven and open-source.
- **License**: MIT (or Apache 2.0)

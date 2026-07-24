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
- **Direct Web UI `.env` Editor**: Rich interactive `.env` key-value editor in the UI with bulk import, environment branching (Development, Staging, Production), and secret masking/unmasking.
- **HashiCorp Vault Integration**:
  - Native integration with HashiCorp Vault KV (Key-Value) Secret Engine.
  - Transparent sync between UI `.env` settings and Vault storage.
  - Automated injection into Kubernetes `Secrets` via Vault Agent Injector or External Secrets Operator (ESO).
- **AES-256 Encryption at Rest**: Fallback/local encrypted storage for standalone setups.

### 3. Modular Git Provider Integration (GitOps Engine)
- **GitHub App First-Class Support**: Seamless repo discovery, OAuth, permission handling, and webhook automation for Push, PR, and Tag/Release events.
- **Provider Abstraction Layer**: Built with extensible provider interfaces to easily support **GitLab**, **Bitbucket**, and self-hosted **Gitea/Forgejo** in future releases.
- **Preview Deployments**: Automatic staging environment creation for every Pull Request / Branch with ephemeral URLs.

### 4. Native ArgoCD Engine Integration
- **Declarative GitOps Management**: Programmatically generate, apply, and sync ArgoCD `Application` Custom Resources (CRDs) via Kubernetes API & ArgoCD REST/gRPC endpoints.
- **Real-Time Health & Log Streaming**: Real-time deployment status, pod health monitoring, and live container logging streamed directly to the Web UI via WebSockets/Server-Sent Events (SSE).
- **Automated Rollback & Sync Policy**: UI-driven manual sync, auto-sync, prune policies, and instant rollbacks to previous git commit revisions.

### 5. Pure UI Manifest Builder (No-Code K8s Abstraction)
- **Zero-YAML Developer Experience**: Intuitive UI for configuring:
  - Framework runtime settings (Laravel workers/scheduler, Next.js standalone SSR).
  - Domain Routing & TLS (Ingress, Gateway API, Cert-Manager integration for free SSL).
  - Horizontal Pod Autoscaling (HPA), Replicas, and Resource Requests/Limits (CPU & Memory).
  - Persistent Volume Claims (PVC) & Managed Database Attachments.
- **Transparent Manifest Compilation**: Users can preview, export, or customize the generated Kubernetes Manifests (Helm Charts / Kustomize / Raw YAML) at any time to avoid vendor lock-in.

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
- [ ] UI `.env` Editor with **HashiCorp Vault** sync.
- [ ] UI Manifest Builder (Deployment, Service, Ingress, Secret).
- [ ] ArgoCD Application controller integration (Create, Sync, Status Check).

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

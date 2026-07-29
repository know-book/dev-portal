<?php

namespace App\Services\ArgoCd;

use App\Contracts\ArgoApplicationGateway;
use App\Data\ArgoApplicationStatus;
use App\Models\Project;

class ArgoApplicationService implements ArgoApplicationGateway
{
    public function __construct(
        private ArgoApplicationDefinitionFactory $definitions,
        private KubernetesApplicationClient $kubernetes,
        private ArgoCdApiClient $argoCd,
    ) {}

    public function reconcile(Project $project): ArgoApplicationStatus
    {
        $definition = $this->definitions->make($project);
        $application = $this->kubernetes->apply($definition);

        return ArgoApplicationStatus::fromApplication(
            $application,
            $this->definitions->applicationName($project),
        );
    }

    public function status(Project $project, bool $hardRefresh = false): ?ArgoApplicationStatus
    {
        if ($this->argoCd->isConfigured()) {
            return $this->argoCd->status($project, $hardRefresh);
        }

        $application = $this->kubernetes->status(
            $this->definitions->applicationName($project),
            $this->definitions->applicationNamespace(),
            $hardRefresh,
        );

        return $application === null
            ? null
            : ArgoApplicationStatus::fromApplication($application, $this->definitions->applicationName($project));
    }

    public function sync(Project $project): ArgoApplicationStatus
    {
        if ($this->argoCd->isConfigured()) {
            return $this->argoCd->sync($project);
        }

        $application = $this->kubernetes->sync(
            $this->definitions->applicationName($project),
            $this->definitions->applicationNamespace(),
        );

        return ArgoApplicationStatus::fromApplication($application, $this->definitions->applicationName($project));
    }
}

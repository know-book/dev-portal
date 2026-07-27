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
        return $this->argoCd->status($project, $hardRefresh);
    }

    public function sync(Project $project): ArgoApplicationStatus
    {
        return $this->argoCd->sync($project);
    }
}

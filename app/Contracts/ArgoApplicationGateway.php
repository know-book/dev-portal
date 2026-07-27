<?php

namespace App\Contracts;

use App\Data\ArgoApplicationStatus;
use App\Models\Project;

interface ArgoApplicationGateway
{
    public function reconcile(Project $project): ArgoApplicationStatus;

    public function status(Project $project, bool $hardRefresh = false): ?ArgoApplicationStatus;

    public function sync(Project $project): ArgoApplicationStatus;
}

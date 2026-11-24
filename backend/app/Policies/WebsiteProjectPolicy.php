<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WebsiteProject;

class WebsiteProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, WebsiteProject $project): bool
    {
        return $user->getKey() === $project->user_id;
    }

    public function update(User $user, WebsiteProject $project): bool
    {
        return $user->getKey() === $project->user_id;
    }

    public function delete(User $user, WebsiteProject $project): bool
    {
        return $user->getKey() === $project->user_id;
    }
}

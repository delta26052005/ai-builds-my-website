<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\DecideBusinessFactCandidateRequest;
use App\Http\Resources\Api\BusinessFactResource;
use App\Models\BusinessFact;
use App\Models\BusinessFactCandidate;
use App\Models\WebsiteProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class BusinessFactDecisionController
{
    public function update(
        DecideBusinessFactCandidateRequest $request,
        WebsiteProject $project,
        BusinessFact $fact,
        BusinessFactCandidate $candidate,
    ): BusinessFactResource {
        Gate::authorize('view', $project);

        $fact = $project->businessFacts()->findOrFail($fact->id);
        $candidate = $fact->candidates()->findOrFail($candidate->id);
        $action = $request->validated('action');

        DB::transaction(function () use ($fact, $candidate, $action): void {
            $lockedFact = BusinessFact::query()
                ->lockForUpdate()
                ->findOrFail($fact->id);
            $lockedCandidate = $lockedFact->candidates()->findOrFail($candidate->id);

            if ($action === 'confirm') {
                $lockedCandidate->update(['verification_status' => 'confirmed']);
                $lockedFact->candidates()
                    ->where('id', '!=', $lockedCandidate->id)
                    ->where('verification_status', '!=', 'rejected')
                    ->update(['verification_status' => 'rejected']);

                return;
            }

            $lockedCandidate->update(['verification_status' => 'rejected']);
            $remaining = $lockedFact->candidates()
                ->where('verification_status', '!=', 'rejected')
                ->lockForUpdate()
                ->get();

            if ($remaining->count() > 1) {
                $lockedFact->candidates()
                    ->where('verification_status', '!=', 'rejected')
                    ->update(['verification_status' => 'conflicting']);
            } elseif ($remaining->count() === 1 && $remaining->first()->verification_status === 'conflicting') {
                $remaining->first()->update(['verification_status' => 'pending']);
            }
        });

        return new BusinessFactResource(
            $fact->load(['candidates.evidence'])->refresh(),
        );
    }
}

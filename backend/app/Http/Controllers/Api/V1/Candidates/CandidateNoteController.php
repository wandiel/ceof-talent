<?php

namespace App\Http\Controllers\Api\V1\Candidates;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\Candidates\CandidateNoteResource;
use App\Models\Candidate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CandidateNoteController extends Controller
{
    public function store(Request $request, Candidate $candidate, AuditLogger $audit): JsonResponse
    {
        $this->authorize('update', $candidate);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:3000']], [], ['body' => 'anotação']);

        $note = $candidate->notes()->create(['body' => trim($data['body']), 'user_id' => $request->user()->id]);
        $audit->log('candidate.note_added', $candidate, 'Registrou uma anotação.', context: ['note' => $note->uuid]);

        return (new CandidateNoteResource($note->load('author')))->response()->setStatusCode(201);
    }
}

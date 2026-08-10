<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCandidateNoteRequest;
use App\Http\Resources\CandidateNoteResource;
use App\Models\Candidate;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CandidateNoteController extends Controller
{
    public function index(Candidate $candidate): AnonymousResourceCollection
    {
        return CandidateNoteResource::collection(
            $candidate->notes()->with('user')->paginate(50)
        );
    }

    public function store(StoreCandidateNoteRequest $request, Candidate $candidate): CandidateNoteResource
    {
        $note = $candidate->notes()->create([
            'user_id' => $request->user()?->id,
            'type' => $request->validated('type', 'note'),
            'body' => $request->validated('body'),
        ]);

        return CandidateNoteResource::make($note->load('user'));
    }
}

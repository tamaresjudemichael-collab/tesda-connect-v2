<?php

namespace App\Http\Controllers;

use App\Models\EnrollmentBatchForwarding;
use App\Support\InstitutionScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EnrollmentBatchForwardingController extends Controller
{
    public function download(Request $request, EnrollmentBatchForwarding $forwarding)
    {
        $user = $request->user();
        abort_unless($user && $user->hasRole('administrator', 'registrar'), 403);

        if (! $user->hasRole('administrator')) {
            abort_unless(
                InstitutionScope::idsFor($user)->contains((int) $forwarding->institution_id),
                403,
                'You may only download summaries for your assigned institution.'
            );
        }

        abort_unless(Storage::disk('local')->exists($forwarding->summary_path), 404);

        return Storage::disk('local')->download(
            $forwarding->summary_path,
            $forwarding->summary_filename,
            ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']
        );
    }
}

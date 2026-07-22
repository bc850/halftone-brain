<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class VisibilityPreferenceController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'see_everyone' => ['required', 'boolean'],
        ]);

        $request->user()->update([
            'see_everyone' => $validated['see_everyone'],
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $validated['see_everyone']
                ? __('Showing everyone’s records.')
                : __('Showing only your records.'),
        ]);

        return back();
    }
}

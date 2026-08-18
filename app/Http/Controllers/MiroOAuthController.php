<?php

namespace App\Http\Controllers;

use App\Services\MiroService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class MiroOAuthController extends Controller
{
    public function connect(MiroService $miro): RedirectResponse
    {
        try {
            return redirect()->away($miro->authorizationUrl());
        } catch (Throwable $e) {
            return redirect()->route('settings.index')->with('error', $e->getMessage());
        }
    }

    public function callback(Request $request, MiroService $miro): RedirectResponse
    {
        if ($request->filled('error')) {
            return redirect()->route('settings.index')
                ->with('error', 'Miro authorization was cancelled or denied: '.$request->string('error')->toString());
        }

        $expectedState = (string) session()->pull('miro_oauth_state');
        $returnedState = $request->string('state')->toString();

        if ($expectedState === '' || $returnedState === '' || !hash_equals($expectedState, $returnedState)) {
            return redirect()->route('settings.index')->with('error', 'Invalid OAuth state. Please try connecting Miro again.');
        }

        $request->validate(['code' => ['required', 'string']]);

        try {
            $miro->exchangeCode($request->string('code')->toString());

            return redirect()->route('settings.index')->with('success', 'Miro connected successfully.');
        } catch (Throwable $e) {
            return redirect()->route('settings.index')->with('error', $e->getMessage());
        }
    }

    public function disconnect(MiroService $miro): RedirectResponse
    {
        $miro->disconnect();

        return redirect()->route('settings.index')->with('success', 'Miro connection removed.');
    }
}

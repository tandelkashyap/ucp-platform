<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MerchantController extends Controller
{
    public function index(Request $request)
    {
        return response()->json($request->user()->merchants);
    }

    public function show(Merchant $merchant)
    {
        $this->authorize('view', $merchant);

        return response()->json($merchant);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $merchant = Merchant::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']).'-'.Str::random(6),
            'status' => 'pending',
        ]);

        // MerchantObserver seeds capability_configs the moment this fires.
        $merchant->users()->attach($request->user(), ['role' => 'owner']);

        return response()->json($merchant->fresh('capabilityConfigs'), 201);
    }
}

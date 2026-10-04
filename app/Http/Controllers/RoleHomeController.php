<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RoleHomeController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route($request->user()->role()->value.'.home');
    }

    public function show(Request $request): View
    {
        return view('role-home', ['role' => $request->user()->role()]);
    }
}

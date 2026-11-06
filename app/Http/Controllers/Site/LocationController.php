<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\View\View;

class LocationController extends Controller
{
    public function __invoke(): View
    {
        return view('site.location', [
            'title' => 'Location',
            'description' => 'Find the villa, plan your arrival, and ask us about transfers or directions.',
            'page' => Page::published()->where('slug', 'location')->first(),
        ]);
    }
}

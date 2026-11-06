<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PolicyController extends Controller
{
    public function index(): View
    {
        return view('site.policies.index', [
            'title' => 'Policies',
            'description' => 'Read the cancellation, privacy, and house policies before sending a booking request.',
            'pages' => Page::published()->orderBy('title')->get(),
        ]);
    }

    public function show(Page $page): View
    {
        abort_unless($page->is_published, 404);

        return view('site.policies.show', [
            'title' => $page->title,
            'description' => Str::limit(strip_tags($page->body ?? ''), 155) ?: null,
            'page' => $page,
        ]);
    }
}

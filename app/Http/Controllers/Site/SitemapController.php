<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\RoomType;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        return response()->view('site.sitemap', [
            'villas' => RoomType::query()->published()->orderBy('name')->get(),
            'pages' => Page::query()->published()->orderBy('title')->get(),
        ])->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}

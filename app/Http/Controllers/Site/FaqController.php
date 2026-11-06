<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use Illuminate\View\View;

class FaqController extends Controller
{
    public function __invoke(): View
    {
        return view('site.faq', [
            'title' => 'FAQ',
            'description' => 'Answers to the practical questions about staying at the villa, from arrival times to cancellation policy.',
            'faqs' => Faq::ordered()->get(),
        ]);
    }
}

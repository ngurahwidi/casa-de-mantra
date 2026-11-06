<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Requests\Site\ContactMessageRequest;
use App\Mail\ContactMessageMail;
use App\Models\PropertyProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function show(): View
    {
        return view('site.contact', [
            'title' => 'Contact',
            'description' => 'Send an enquiry about your dates, the villa, or your arrival. We reply personally.',
        ]);
    }

    public function store(ContactMessageRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $profile = rescue(fn () => PropertyProfile::current(), null, false);

        if ($profile?->email) {
            Mail::to($profile->email)->send(new ContactMessageMail(
                senderName: $data['name'],
                senderEmail: $data['email'],
                senderPhone: $data['phone'] ?? null,
                messageBody: $data['message'],
                villa: $data['villa'] ?? null,
                propertyName: $profile->name,
            ));
        }

        return redirect()
            ->route('contact')
            ->with('status', 'Thank you. Your message has been sent and we will reply as soon as possible.');
    }
}

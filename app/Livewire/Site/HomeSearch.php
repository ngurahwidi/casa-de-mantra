<?php

namespace App\Livewire\Site;

use App\Data\Site\StayQuery;
use App\Services\Site\StaySearch;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Date and guest picker in the home page hero.
 *
 * A search is a plain link back to the home page with the dates in the query string,
 * so results can be refreshed, shared and opened in a new tab. The villa cards below
 * read that query and ask StaySearch whether each villa can be booked.
 */
class HomeSearch extends Component
{
    /** The largest party any published villa can sleep. */
    #[Locked]
    public int $maxGuests = 12;

    /** Y-m-d or empty */
    public string $checkIn = '';

    /** Y-m-d or empty */
    public string $checkOut = '';

    public int $guests = 2;

    public function mount(StaySearch $search): void
    {
        $this->maxGuests = max(1, $this->maxGuests);
        $this->guests = $this->clampGuests($this->guests);

        $query = StayQuery::make(
            request()->query('check_in'),
            request()->query('check_out'),
            request()->query('guests'),
            $this->maxGuests,
        );

        if ($query === null || ! $this->inBookingWindow($query, $search)) {
            return;
        }

        $this->checkIn = $query->checkIn->toDateString();
        $this->checkOut = $query->checkOut->toDateString();
        $this->guests = $query->guests;
    }

    public function updatedCheckIn(): void
    {
        $this->resetErrorBag();

        if ($this->checkOut !== '' && $this->checkOut <= $this->checkIn) {
            $this->checkOut = '';
        }
    }

    public function updatedGuests(): void
    {
        $this->guests = $this->clampGuests($this->guests);
    }

    /**
     * Sends the guest back to the villa list with these dates and guests.
     */
    public function search(StaySearch $search): void
    {
        $this->resetErrorBag();

        if ($this->guests < 1) {
            $this->addError('guests', 'Choose at least one guest.');

            return;
        }

        $query = StayQuery::make($this->checkIn, $this->checkOut, $this->guests, $this->maxGuests);

        if ($query === null) {
            if ($this->checkIn === '') {
                $this->addError('checkIn', 'Choose a check-in date.');

                return;
            }

            if ($this->checkOut === '') {
                $this->addError('checkOut', 'Choose a check-out date.');

                return;
            }

            $this->addError('checkOut', 'Check-out must be after check-in.');

            return;
        }

        if ($query->checkIn < $search->today()) {
            $this->addError('checkIn', 'Check-in cannot be in the past.');

            return;
        }

        if ($query->checkOut > $search->lastCheckOut()) {
            $this->addError('checkOut', 'These dates are too far ahead to book yet.');

            return;
        }

        $this->redirect(route('home', $query->query()).'#villas', navigate: true);
    }

    public function render(): View
    {
        $search = app(StaySearch::class);

        return view('livewire.site.home-search', [
            'today' => $search->today()->toDateString(),
            'lastCheckIn' => $search->lastCheckOut()->subDay()->toDateString(),
            'lastCheckOut' => $search->lastCheckOut()->toDateString(),
            'checkInLabel' => $this->formatDate($this->checkIn),
            'checkOutLabel' => $this->formatDate($this->checkOut),
        ]);
    }

    private function clampGuests(int $guests): int
    {
        return max(1, min($guests, $this->maxGuests));
    }

    private function inBookingWindow(StayQuery $query, StaySearch $search): bool
    {
        return $query->checkIn >= $search->today() && $query->checkOut <= $search->lastCheckOut();
    }

    private function formatDate(string $date): ?string
    {
        return $date === '' ? null : CarbonImmutable::parse($date)->format('j M Y');
    }
}

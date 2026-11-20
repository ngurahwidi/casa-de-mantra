<?php

namespace App\Livewire\Site;

use App\Data\Site\CalendarDay;
use App\Data\Site\StayQuery;
use App\Models\RoomType;
use App\Services\Site\StaySearch;
use App\Services\Site\VillaCatalog;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Date and guest picker in the home page hero.
 *
 * The guest picks a check-in, then a check-out, on a month calendar that shows which
 * nights at least one published villa can still sell. A search is a plain link back to
 * the home page with the dates in the query string, so results can be refreshed, shared
 * and opened in a new tab. The villa cards below read that query and ask StaySearch
 * whether each villa can be booked.
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

    /** The calendar month on screen, Y-m */
    #[Locked]
    public string $month = '';

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

        if ($query !== null && $this->inBookingWindow($query, $search)) {
            $this->checkIn = $query->checkIn->toDateString();
            $this->checkOut = $query->checkOut->toDateString();
            $this->guests = $query->guests;
        }

        $this->month = $this->clampMonth(
            CarbonImmutable::parse($this->checkIn !== '' ? $this->checkIn : $search->today()),
        )->format('Y-m');
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
     * Picks a date on the calendar.
     *
     * $target says which box the guest opened the calendar from: "out" edits the check-out
     * date, anything else starts a new stay. While a check-in waits for its check-out, a
     * later date that still leaves a bookable stay at one of the villas becomes the check-out.
     * Any other date that can start a stay becomes the new check-in.
     */
    public function selectDate(string $date, StaySearch $search, ?string $target = null): void
    {
        $date = self::parseDate($date);

        if ($date === null) {
            return;
        }

        $this->resetErrorBag();

        if ($target === 'out' && $this->checkIn !== '') {
            if ($date > $this->checkIn && $this->canCheckOut($date, $search)) {
                $this->checkOut = $date;
                $this->dispatch('home-search-complete');

                return;
            }

            if ($date <= $this->checkIn) {
                $this->startStay($date, $search);

                return;
            }

            $this->addError('checkOut', 'Those dates are not available together. Choose another check-out date.');

            return;
        }

        if ($this->checkIn !== '' && $this->checkOut === '' && $date > $this->checkIn
            && $this->canCheckOut($date, $search)) {
            $this->checkOut = $date;
            $this->dispatch('home-search-complete');

            return;
        }

        $this->startStay($date, $search);
    }

    /**
     * Starts a new stay on the date when at least one villa can sell its first night.
     */
    private function startStay(string $date, StaySearch $search): void
    {
        if (! $this->canCheckIn($date, $search)) {
            return;
        }

        $this->checkIn = $date;
        $this->checkOut = '';
    }

    public function clearDates(): void
    {
        $this->checkIn = '';
        $this->checkOut = '';
        $this->resetErrorBag();
    }

    public function previousMonth(): void
    {
        $this->month = $this->clampMonth(CarbonImmutable::parse($this->month.'-01')->subMonth())->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->clampMonth(CarbonImmutable::parse($this->month.'-01')->addMonth())->format('Y-m');
    }

    #[Computed]
    public function canGoBack(): bool
    {
        return $this->month > app(StaySearch::class)->today()->format('Y-m');
    }

    #[Computed]
    public function canGoForward(): bool
    {
        return $this->month < app(StaySearch::class)->lastCheckOut()->format('Y-m');
    }

    /**
     * The month on screen, each date with how the guest can use it right now.
     *
     * A night is available when at least one published villa can sell it, so the calendar
     * only closes dates that no villa can take. state: past, unavailable, available,
     * check-in, check-out or in-range. selectable: whether clicking it changes the selection.
     *
     * @return array{label: string, leadingBlanks: int, days: list<array{date: string, day: int, state: string, selectable: bool}>}
     */
    #[Computed]
    public function calendar(): array
    {
        $search = app(StaySearch::class);
        $month = $this->clampMonth(CarbonImmutable::parse($this->month.'-01'));

        $sellable = [];
        $days = [];

        foreach ($this->villas() as $villa) {
            foreach ($search->calendar($villa, $month)[0]->days as $day) {
                if ($day->isSellable) {
                    $sellable[$day->date] = true;
                }

                $days[$day->date] ??= $day;
            }
        }

        if ($days === []) {
            foreach (CarbonPeriod::create($month, $month->endOfMonth()->startOfDay()) as $date) {
                $days[$date->toDateString()] = new CalendarDay(
                    date: $date->toDateString(),
                    dayOfMonth: $date->day,
                    isPast: $date->toDateString() < $search->today()->toDateString(),
                    isSellable: false,
                );
            }
        }

        $awaitingCheckOut = $this->checkIn !== '' && $this->checkOut === '';
        $latestCheckOut = $awaitingCheckOut ? $this->latestCheckOut($search) : '';

        $calendarDays = [];

        foreach ($days as $date => $day) {
            $canCheckIn = ! $day->isPast && isset($sellable[$date]);
            $canCheckOut = $awaitingCheckOut && $date > $this->checkIn && $date <= $latestCheckOut;

            $state = match (true) {
                $date === $this->checkIn => 'check-in',
                $date === $this->checkOut => 'check-out',
                $this->checkOut !== '' && $date > $this->checkIn && $date < $this->checkOut => 'in-range',
                $day->isPast => 'past',
                $canCheckIn || $canCheckOut => 'available',
                default => 'unavailable',
            };

            $calendarDays[] = [
                'date' => $date,
                'day' => $day->dayOfMonth,
                'state' => $state,
                'selectable' => $canCheckIn || $canCheckOut,
            ];
        }

        return [
            'label' => $month->format('F Y'),
            'leadingBlanks' => $month->dayOfWeekIso - 1,
            'days' => $calendarDays,
        ];
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
        return view('livewire.site.home-search', [
            'checkInLabel' => $this->formatDate($this->checkIn),
            'checkOutLabel' => $this->formatDate($this->checkOut),
        ]);
    }

    private function clampGuests(int $guests): int
    {
        return max(1, min($guests, $this->maxGuests));
    }

    /**
     * Whether the date can start a stay at one of the villas.
     */
    private function canCheckIn(string $date, StaySearch $search): bool
    {
        if ($date < $search->today()->toDateString() || $date >= $search->lastCheckOut()->toDateString()) {
            return false;
        }

        return $this->villas()->contains(
            fn (RoomType $villa): bool => $search->canCheckIn($villa, CarbonImmutable::parse($date)),
        );
    }

    /**
     * Whether the date can end the stay that starts on the chosen check-in.
     */
    private function canCheckOut(string $date, StaySearch $search): bool
    {
        return $date > $this->checkIn && $date <= $this->latestCheckOut($search);
    }

    /**
     * The latest check-out any villa allows for the chosen check-in. A check-out on the first
     * unsellable night is fine, because the guest leaves that morning.
     */
    private function latestCheckOut(StaySearch $search): string
    {
        $latest = $this->checkIn;

        foreach ($this->villas() as $villa) {
            $latest = max($latest, $search->latestCheckOut($villa, CarbonImmutable::parse($this->checkIn)));
        }

        return $latest;
    }

    /**
     * The published villas the search covers, memoised for the request.
     *
     * @return Collection<int, RoomType>
     */
    private function villas(): Collection
    {
        return once(fn (): Collection => app(VillaCatalog::class)->published());
    }

    /**
     * Keeps the calendar between the current month and the month of the last searchable check-out.
     */
    private function clampMonth(CarbonImmutable $month): CarbonImmutable
    {
        $search = app(StaySearch::class);
        $first = $search->today()->startOfMonth();
        $last = $search->lastCheckOut()->startOfMonth();

        return $month->startOfMonth()->max($first)->min($last);
    }

    private function inBookingWindow(StayQuery $query, StaySearch $search): bool
    {
        return $query->checkIn >= $search->today() && $query->checkOut <= $search->lastCheckOut();
    }

    private function formatDate(string $date): ?string
    {
        return $date === '' ? null : CarbonImmutable::parse($date)->format('j M Y');
    }

    /**
     * The date as Y-m-d when it is a real calendar date in that format, otherwise null.
     */
    private static function parseDate(string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $value : null;
    }
}

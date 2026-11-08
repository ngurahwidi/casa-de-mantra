<?php

namespace App\Livewire\Site;

use App\Data\Site\StaySearchResult;
use App\Models\PropertyProfile;
use App\Models\RoomType;
use App\Services\Site\StaySearch;
use App\Support\WhatsApp;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Date and guest picker on the villa page.
 *
 * The guest picks a check-in, then a check-out, on a month calendar; every answer about
 * availability, prices and alternative dates comes from StaySearch. Dates only change through
 * the actions below, which re-check them on the server, and they are kept in the query string
 * so the selection survives a reload and can be shared.
 *
 * @property-read StaySearchResult|null $result
 * @property-read array{label: string, leadingBlanks: int, days: list<array{date: string, day: int, state: string, selectable: bool}>} $calendar
 * @property-read bool $canGoBack
 * @property-read bool $canGoForward
 * @property-read string $currency
 * @property-read string|null $whatsappUrl
 * @property-read string $guestSummary
 */
class AvailabilitySearch extends Component
{
    public RoomType $villa;

    /** Y-m-d or empty */
    #[Locked]
    #[Url(as: 'check_in', except: '')]
    public string $checkIn = '';

    /** Y-m-d or empty; only set together with checkIn */
    #[Locked]
    #[Url(as: 'check_out', except: '')]
    public string $checkOut = '';

    #[Url(except: 2)]
    public int $adults = 2;

    #[Url(except: 0)]
    public int $children = 0;

    /** The calendar month on screen, Y-m */
    #[Locked]
    public string $month = '';

    public function mount(StaySearch $search): void
    {
        $this->adults = $this->clamp($this->adults, 1, $this->villa->max_adults);
        $this->children = $this->clamp($this->children, 0, $this->villa->max_children);

        $checkIn = self::parseDate($this->checkIn);
        $checkOut = self::parseDate($this->checkOut);

        if ($checkIn === null || $checkOut === null || $checkOut <= $checkIn) {
            $this->checkOut = '';
            $checkOut = null;
        }

        $this->checkIn = $checkIn ?? '';
        $this->checkOut = $checkOut ?? '';

        $this->month = $this->clampMonth(CarbonImmutable::parse($checkIn ?? $search->today()))->format('Y-m');
    }

    public function updatedAdults(): void
    {
        $this->adults = $this->clamp($this->adults, 1, $this->villa->max_adults);
    }

    public function updatedChildren(): void
    {
        $this->children = $this->clamp($this->children, 0, $this->villa->max_children);
    }

    /**
     * Picks a date on the calendar.
     *
     * While a check-in waits for its check-out, a later date up to the first unavailable night
     * becomes the check-out. Any other date that can start a stay becomes the new check-in.
     */
    public function selectDate(string $date, StaySearch $search): void
    {
        $date = self::parseDate($date);

        if ($date === null) {
            return;
        }

        if ($this->checkIn !== '' && $this->checkOut === '' && $date > $this->checkIn
            && $date <= $search->latestCheckOut($this->villa, CarbonImmutable::parse($this->checkIn))) {
            $this->checkOut = $date;

            return;
        }

        if ($search->canCheckIn($this->villa, CarbonImmutable::parse($date))) {
            $this->checkIn = $date;
            $this->checkOut = '';
        }
    }

    public function clearDates(): void
    {
        $this->checkIn = '';
        $this->checkOut = '';
    }

    /**
     * Switches to the nearest available stay that the last search suggested.
     */
    public function useAlternative(): void
    {
        $alternative = $this->result?->alternative;

        if ($alternative === null) {
            return;
        }

        $this->checkIn = $alternative->checkIn;
        $this->checkOut = $alternative->checkOut;
        $this->month = $this->clampMonth(CarbonImmutable::parse($alternative->checkIn))->format('Y-m');

        unset($this->result, $this->calendar);
    }

    public function previousMonth(): void
    {
        $this->month = $this->clampMonth(CarbonImmutable::parse($this->month.'-01')->subMonth())->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->clampMonth(CarbonImmutable::parse($this->month.'-01')->addMonth())->format('Y-m');
    }

    /**
     * The search for the chosen dates, or null until both dates are chosen.
     */
    #[Computed]
    public function result(): ?StaySearchResult
    {
        if ($this->checkIn === '' || $this->checkOut === '') {
            return null;
        }

        return app(StaySearch::class)->search(
            $this->villa,
            CarbonImmutable::parse($this->checkIn),
            CarbonImmutable::parse($this->checkOut),
            $this->adults,
            $this->children,
        );
    }

    /**
     * The month on screen, each date with how the guest can use it right now.
     *
     * state: past, unavailable, available, check-in, check-out or in-range.
     * selectable: whether clicking it changes the selection (see selectDate).
     *
     * @return array{label: string, leadingBlanks: int, days: list<array{date: string, day: int, state: string, selectable: bool}>}
     */
    #[Computed]
    public function calendar(): array
    {
        $search = app(StaySearch::class);
        $month = $search->calendar($this->villa, CarbonImmutable::parse($this->month.'-01'))[0];

        $awaitingCheckOut = $this->checkIn !== '' && $this->checkOut === '';
        $latestCheckOut = $awaitingCheckOut
            ? $search->latestCheckOut($this->villa, CarbonImmutable::parse($this->checkIn))
            : null;

        $days = [];

        foreach ($month->days as $day) {
            $canCheckIn = ! $day->isPast && $day->isSellable;
            $canCheckOut = $awaitingCheckOut && $day->date > $this->checkIn && $day->date <= $latestCheckOut;

            $state = match (true) {
                $day->date === $this->checkIn => 'check-in',
                $day->date === $this->checkOut => 'check-out',
                $this->checkOut !== '' && $day->date > $this->checkIn && $day->date < $this->checkOut => 'in-range',
                $day->isPast => 'past',
                $canCheckIn || $canCheckOut => 'available',
                default => 'unavailable',
            };

            $days[] = [
                'date' => $day->date,
                'day' => $day->dayOfMonth,
                'state' => $state,
                'selectable' => $canCheckIn || $canCheckOut,
            ];
        }

        return ['label' => $month->label, 'leadingBlanks' => $month->leadingBlanks, 'days' => $days];
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

    #[Computed]
    public function currency(): string
    {
        return PropertyProfile::current()->currency;
    }

    /**
     * A WhatsApp chat about the chosen dates, or a general enquiry before any are chosen.
     */
    #[Computed]
    public function whatsappUrl(): ?string
    {
        $profile = PropertyProfile::current();

        if ($this->checkIn === '' || $this->checkOut === '') {
            return WhatsApp::url($profile);
        }

        return WhatsApp::url($profile, sprintf(
            'Hello %s, I would like to ask about %s from %s to %s for %s.',
            $profile->name,
            $this->villa->name,
            CarbonImmutable::parse($this->checkIn)->format('j M Y'),
            CarbonImmutable::parse($this->checkOut)->format('j M Y'),
            $this->guestSummary,
        ));
    }

    #[Computed]
    public function guestSummary(): string
    {
        $summary = $this->adults.' '.str('adult')->plural($this->adults);

        if ($this->children > 0) {
            $summary .= ', '.$this->children.' '.str('child')->plural($this->children);
        }

        return $summary;
    }

    public function render(): View
    {
        return view('livewire.site.availability-search');
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

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($value, max($min, $max)));
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

{{--
    Room type picker of the Calendar page (AvailabilityCalendar::selectRoomType()); only shown with several room types.
--}}
<div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem">
    <label for="availability-calendar-room-type" style="font-size: 0.875rem; font-weight: 500">Room type</label>

    <div style="min-width: 16rem">
        <x-filament::input.wrapper>
            <x-filament::input.select
                id="availability-calendar-room-type"
                wire:change="selectRoomType($event.target.value)"
            >
                @foreach ($roomTypes as $roomType)
                    <option value="{{ $roomType->getKey() }}" @selected($roomType->getKey() === $selected)>
                        {{ $roomType->name }} ({{ trans_choice(':count unit|:count units', $roomType->units_count) }})
                    </option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </div>
</div>

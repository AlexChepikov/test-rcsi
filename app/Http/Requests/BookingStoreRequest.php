<?php

namespace App\Http\Requests;

use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BookingStoreRequest extends FormRequest
{
    private const MIN_DURATION_MINUTES = 15;

    private const MAX_DURATION_MINUTES = 240;

    private const SLOT_STEP_MINUTES = 15;

    private const WORKDAY_START_HOUR = 9;

    private const WORKDAY_END_HOUR = 20;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ];
    }

    public function messages(): array
    {
        return [
            'room_id.required' => 'Укажите комнату.',
            'room_id.integer' => 'Идентификатор комнаты должен быть целым числом.',
            'room_id.exists' => 'Комната не найдена.',
            'title.required' => 'Укажите название встречи.',
            'title.string' => 'Название встречи должно быть строкой.',
            'title.max' => 'Название встречи не должно превышать 255 символов.',
            'starts_at.required' => 'Укажите время начала.',
            'starts_at.date' => 'Время начала должно быть передано в формате ISO 8601.',
            'ends_at.required' => 'Укажите время окончания.',
            'ends_at.date' => 'Время окончания должно быть передано в формате ISO 8601.',
            'ends_at.after' => 'Время окончания должно быть позже времени начала.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->validateBookingWindow($validator);
        });
    }

    public function startsAtUtc(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->input('starts_at'))->utc();
    }

    public function endsAtUtc(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->input('ends_at'))->utc();
    }

    private function validateBookingWindow(Validator $validator): void
    {
        $room = Room::query()->with('office')->find($this->integer('room_id'));

        if ($room === null || $room->office === null) {
            return;
        }

        $startsAt = $this->startsAtUtc();
        $endsAt = $this->endsAtUtc();

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            return;
        }

        $timezone = $room->office->timezone;
        $localStartsAt = $startsAt->setTimezone($timezone);
        $localEndsAt = $endsAt->setTimezone($timezone);

        $this->validateDuration($validator, $startsAt, $endsAt);
        $this->validateSlotStep($validator, $localStartsAt);
        $this->validateWorkingHours($validator, $localStartsAt, $localEndsAt);
    }

    private function validateDuration(Validator $validator, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        $durationSeconds = $endsAt->getTimestamp() - $startsAt->getTimestamp();
        $durationMinutes = intdiv($durationSeconds, 60);

        if ($durationMinutes < self::MIN_DURATION_MINUTES) {
            $validator->errors()->add('ends_at', 'Минимальная длительность брони — 15 минут.');

            return;
        }

        if ($durationMinutes > self::MAX_DURATION_MINUTES) {
            $validator->errors()->add('ends_at', 'Максимальная длительность брони — 4 часа.');

            return;
        }

        if ($durationSeconds % (self::SLOT_STEP_MINUTES * 60) !== 0) {
            $validator->errors()->add('ends_at', 'Длительность брони должна быть кратна 15 минутам.');
        }
    }

    private function validateSlotStep(Validator $validator, CarbonImmutable $localStartsAt): void
    {
        if ($localStartsAt->second !== 0 || $localStartsAt->minute % self::SLOT_STEP_MINUTES !== 0) {
            $validator->errors()->add('starts_at', 'Время начала должно быть кратно 15 минутам.');
        }
    }

    private function validateWorkingHours(
        Validator $validator,
        CarbonImmutable $localStartsAt,
        CarbonImmutable $localEndsAt,
    ): void {
        $workdayStartsAt = $localStartsAt->setTime(self::WORKDAY_START_HOUR, 0);
        $workdayEndsAt = $localStartsAt->setTime(self::WORKDAY_END_HOUR, 0);

        if ($localStartsAt->lessThan($workdayStartsAt) || $localEndsAt->greaterThan($workdayEndsAt)) {
            $validator->errors()->add(
                'starts_at',
                'Бронь должна полностью укладываться в рабочее время офиса с 09:00 до 20:00.',
            );
        }
    }
}

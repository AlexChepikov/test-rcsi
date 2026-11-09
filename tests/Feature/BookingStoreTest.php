<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $office = Office::factory()->create(['timezone' => 'Europe/Moscow']);
        $this->room = Room::factory()->for($office)->create();
    }

    public function test_creates_booking_within_working_hours(): void
    {
        $this->actingAsSanctumUser();

        $response = $this->postJson('/api/bookings', $this->payload('2026-11-10 10:00', '2026-11-10 11:00'));

        $response->assertCreated()
            ->assertJsonPath('room_id', $this->room->id)
            ->assertJsonPath('user_id', $this->user->id)
            ->assertJsonPath('title', 'Планёрка');

        $this->assertDatabaseHas('bookings', [
            'room_id' => $this->room->id,
            'user_id' => $this->user->id,
            'starts_at' => '2026-11-10 07:00:00',
            'ends_at' => '2026-11-10 08:00:00',
        ]);
    }

    public function test_stores_times_in_utc_regardless_of_client_offset(): void
    {
        $this->actingAsSanctumUser();

        $response = $this->postJson('/api/bookings', $this->payload(
            '2026-11-10 10:00',
            '2026-11-10 11:00',
            overrides: [
                'starts_at' => '2026-11-10T08:00:00+01:00',
                'ends_at' => '2026-11-10T09:00:00+01:00',
            ],
        ));

        $response->assertCreated();

        $this->assertDatabaseHas('bookings', [
            'starts_at' => '2026-11-10 07:00:00',
            'ends_at' => '2026-11-10 08:00:00',
        ]);
    }

    public function test_ignores_client_supplied_user_id(): void
    {
        $this->actingAsSanctumUser();
        $otherUser = User::factory()->create();

        $response = $this->postJson('/api/bookings', $this->payload(
            '2026-11-10 10:00',
            '2026-11-10 11:00',
            overrides: ['user_id' => $otherUser->id],
        ));

        $response->assertCreated()->assertJsonPath('user_id', $this->user->id);
    }

    public function test_allows_adjacent_bookings(): void
    {
        $this->actingAsSanctumUser();
        $this->createExistingBooking('2026-11-10 10:00', '2026-11-10 11:00');

        $this->postJson('/api/bookings', $this->payload('2026-11-10 11:00', '2026-11-10 12:00'))
            ->assertCreated();

        $this->postJson('/api/bookings', $this->payload('2026-11-10 09:00', '2026-11-10 10:00'))
            ->assertCreated();

        $this->assertDatabaseCount('bookings', 3);
    }

    public function test_rejects_overlapping_booking(): void
    {
        $this->actingAsSanctumUser();
        $this->createExistingBooking('2026-11-10 10:00', '2026-11-10 11:00');

        $this->postJson('/api/bookings', $this->payload('2026-11-10 10:30', '2026-11-10 11:30'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_rejects_booking_overlapping_from_the_left(): void
    {
        $this->actingAsSanctumUser();
        $this->createExistingBooking('2026-11-10 10:00', '2026-11-10 11:00');

        $this->postJson('/api/bookings', $this->payload('2026-11-10 09:30', '2026-11-10 10:30'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_rejects_booking_that_fully_covers_existing(): void
    {
        $this->actingAsSanctumUser();
        $this->createExistingBooking('2026-11-10 10:30', '2026-11-10 11:00');

        $this->postJson('/api/bookings', $this->payload('2026-11-10 10:00', '2026-11-10 11:30'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_rejects_duration_shorter_than_15_minutes(): void
    {
        $this->actingAsSanctumUser();

        $this->postJson('/api/bookings', $this->payload('2026-11-10 10:00', '2026-11-10 10:10'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_accepts_minimum_15_minute_duration(): void
    {
        $this->actingAsSanctumUser();

        $this->postJson('/api/bookings', $this->payload('2026-11-10 10:00', '2026-11-10 10:15'))
            ->assertCreated();
    }

    public function test_accepts_maximum_4_hour_duration(): void
    {
        $this->actingAsSanctumUser();

        $this->postJson('/api/bookings', $this->payload('2026-11-10 09:00', '2026-11-10 13:00'))
            ->assertCreated();
    }

    public function test_rejects_duration_longer_than_4_hours(): void
    {
        $this->actingAsSanctumUser();

        $this->postJson('/api/bookings', $this->payload('2026-11-10 09:00', '2026-11-10 13:15'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_rejects_duration_not_multiple_of_15_minutes(): void
    {
        $this->actingAsSanctumUser();

        $this->postJson('/api/bookings', $this->payload('2026-11-10 10:00', '2026-11-10 10:20'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_rejects_start_not_on_15_minute_grid(): void
    {
        $this->actingAsSanctumUser();

        $this->postJson('/api/bookings', $this->payload('2026-11-10 10:07', '2026-11-10 10:22'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_accepts_booking_starting_at_09_00(): void
    {
        $this->actingAsSanctumUser();

        $this->postJson('/api/bookings', $this->payload('2026-11-10 09:00', '2026-11-10 09:15'))
            ->assertCreated();
    }

    public function test_accepts_booking_ending_at_20_00(): void
    {
        $this->actingAsSanctumUser();

        $this->postJson('/api/bookings', $this->payload('2026-11-10 19:45', '2026-11-10 20:00'))
            ->assertCreated();
    }

    public function test_rejects_booking_starting_before_working_hours(): void
    {
        $this->actingAsSanctumUser();

        $this->postJson('/api/bookings', $this->payload('2026-11-10 08:45', '2026-11-10 09:15'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_rejects_booking_ending_after_working_hours(): void
    {
        $this->actingAsSanctumUser();

        $this->postJson('/api/bookings', $this->payload('2026-11-10 19:45', '2026-11-10 20:15'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_working_hours_are_checked_in_the_office_timezone(): void
    {
        $this->actingAsSanctumUser();
        $novosibirsk = Office::factory()->novosibirsk()->create();
        $room = Room::factory()->for($novosibirsk)->create();

        // 16:00 UTC is 23:00 in Novosibirsk (outside 09:00-20:00).
        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-10T16:00:00+00:00',
            'ends_at' => '2026-11-10T17:00:00+00:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('starts_at');

        // 03:00 UTC is 10:00 in Novosibirsk (inside working hours, unlike Moscow).
        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-10T03:00:00+00:00',
            'ends_at' => '2026-11-10T04:00:00+00:00',
        ])->assertCreated();
    }

    public function test_rejects_unknown_room(): void
    {
        $this->actingAsSanctumUser();

        $this->postJson('/api/bookings', $this->payload(
            '2026-11-10 10:00',
            '2026-11-10 11:00',
            overrides: ['room_id' => 999999],
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('room_id');
    }

    public function test_rejects_booking_when_end_is_not_after_start(): void
    {
        $this->actingAsSanctumUser();

        $this->postJson('/api/bookings', $this->payload('2026-11-10 11:00', '2026-11-10 10:00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_has_no_restrictions_on_dates(): void
    {
        $this->actingAsSanctumUser();

        $this->postJson('/api/bookings', $this->payload('2000-01-01 10:00', '2000-01-01 11:00'))
            ->assertCreated();

        $this->postJson('/api/bookings', $this->payload('2099-12-31 10:00', '2099-12-31 11:00'))
            ->assertCreated();
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/bookings', $this->payload('2026-11-10 10:00', '2026-11-10 11:00'))
            ->assertUnauthorized();
    }

    private function actingAsSanctumUser(): void
    {
        Sanctum::actingAs($this->user);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(string $startsAt, string $endsAt, ?Room $room = null, array $overrides = []): array
    {
        return array_merge([
            'room_id' => ($room ?? $this->room)->id,
            'title' => 'Планёрка',
            'starts_at' => $this->iso($startsAt),
            'ends_at' => $this->iso($endsAt),
        ], $overrides);
    }

    private function createExistingBooking(string $startsAt, string $endsAt): Booking
    {
        return Booking::factory()->for($this->room)->for($this->user)->create([
            'starts_at' => $this->instant($startsAt),
            'ends_at' => $this->instant($endsAt),
        ]);
    }

    private function iso(string $dateTime, string $timezone = 'Europe/Moscow'): string
    {
        return $this->instant($dateTime, $timezone)->toIso8601String();
    }

    private function instant(string $dateTime, string $timezone = 'Europe/Moscow'): CarbonImmutable
    {
        return CarbonImmutable::parse($dateTime, $timezone)->utc();
    }
}

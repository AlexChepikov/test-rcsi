<?php

namespace App\Http\Controllers;

use App\Http\Requests\BookingStoreRequest;
use App\Models\Booking;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as HTTPResponse;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(BookingStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $startsAt = $request->startsAtUtc();
        $endsAt = $request->endsAtUtc();

        $booking = DB::transaction(function () use ($request, $validated, $startsAt, $endsAt): Booking {
            Room::lockForUpdate()->find($validated['room_id']);
            
            $hasConflict = Booking::query()
                ->where('room_id', $validated['room_id'])
                ->overlapping($startsAt, $endsAt)
                ->exists();

            if ($hasConflict) {
                throw ValidationException::withMessages([
                    'starts_at' => 'Комната уже забронирована на это время.',
                ]);
            }

            return Booking::create([
                'room_id' => $validated['room_id'],
                'user_id' => $request->user()->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'title' => $validated['title'],
            ]);
        });

        Log::channel('bookings')->info('Booking created', [
            'booking_id' => $booking->id,
            'user_id' => $booking->user_id,
            'room_id' => $booking->room_id,
            'starts_at' => $booking->starts_at?->toIso8601String(),
            'ends_at' => $booking->ends_at?->toIso8601String(),
        ]);

        return response()->json($booking, HTTPResponse::HTTP_CREATED);
    }

    public function destroy(Request $request, Booking $booking): Response
    {
        abort_unless(
            (int) $booking->user_id === (int) $request->user()->id,
            HTTPResponse::HTTP_FORBIDDEN,
            'Отменить бронь может только её автор.',
        );

        $booking->delete();

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at.' – '.$b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}

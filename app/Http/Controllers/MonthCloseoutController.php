<?php

namespace App\Http\Controllers;

use App\Models\MonthHardClose;
use App\Services\MonthCloseoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class MonthCloseoutController extends Controller
{
    public function __construct(private readonly MonthCloseoutService $closeoutService) {}

    public function status(Request $request): JsonResponse
    {
        $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $user = auth()->user();
        if (! $user->family_id) {
            return response()->json(['message' => 'User must be in a family'], 403);
        }

        $status = $this->closeoutService->getMonthStatus($user->family, (int) $request->year, (int) $request->month);

        return response()->json($status);
    }

    public function softClose(Request $request): JsonResponse
    {
        $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $user = auth()->user();
        if (! $user->family_id) {
            return response()->json(['message' => 'User must be in a family'], 403);
        }

        try {
            $result = $this->closeoutService->softClose($user, (int) $request->year, (int) $request->month);

            $response = [
                'message' => 'Month soft-closed successfully',
                'data' => $result['soft_close'],
            ];

            if ($result['hard_close'] !== null) {
                $response['message'] = 'Month closed successfully';
                $response['hard_close'] = $result['hard_close'];
                $response['auto_hard_closed'] = true;
            }

            return response()->json($response);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function undoSoftClose(Request $request): JsonResponse
    {
        $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $user = auth()->user();
        if (! $user->family_id) {
            return response()->json(['message' => 'User must be in a family'], 403);
        }

        try {
            $this->closeoutService->undoSoftClose($user, (int) $request->year, (int) $request->month);

            return response()->json(['message' => 'Soft close undone']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function hardClose(Request $request): JsonResponse
    {
        $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $user = auth()->user();
        if (! $user->family_id) {
            return response()->json(['message' => 'User must be in a family'], 403);
        }

        if (! $user->can_manage_family) {
            abort(403);
        }

        try {
            $hardClose = $this->closeoutService->hardClose($user->family, $user, (int) $request->year, (int) $request->month);

            return response()->json([
                'message' => 'Month hard-closed successfully',
                'data' => $hardClose,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function closedMonths(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (! $user->family_id) {
            return response()->json([]);
        }

        $closes = MonthHardClose::query()
            ->where('family_id', $user->family_id)
            ->get(['year', 'month']);

        return response()->json($closes);
    }
}

<?php

namespace App\Services;

readonly class SplitCalculator
{
    /**
     * Validates that split percentages sum to 100% within acceptable epsilon.
     *
     * @param  array<array{user_id: int, share_percentage: float}>  $splits
     */
    public static function validate(array $splits): bool
    {
        $total = array_sum(array_column($splits, 'share_percentage'));
        $epsilon = 0.01;

        return abs($total - 100.0) <= $epsilon;
    }

    /**
     * Allocates a total amount across splits with proper rounding.
     *
     * The last split absorbs any rounding difference to ensure the total always
     * equals exactly the input amount. All splits are included even if the
     * calculated amount is 0.00.
     *
     * @param  float  $total  The total amount to allocate
     * @param  array<array{user_id: int, share_percentage: float}>  $splits
     * @return array<array{user_id: int, share_percentage: float, amount: float}>
     */
    public static function allocate(float $total, array $splits): array
    {
        if (empty($splits)) {
            return [];
        }

        $allocated = [];
        $remainingAmount = $total;

        foreach ($splits as $index => $split) {
            $isLast = $index === count($splits) - 1;

            if ($isLast) {
                // Last split gets the remainder to handle rounding errors
                $amount = round($remainingAmount, 2);
            } else {
                $amount = round($total * ($split['share_percentage'] / 100), 2);
                $remainingAmount -= $amount;
            }

            $allocated[] = [
                'user_id' => $split['user_id'],
                'share_percentage' => $split['share_percentage'],
                'amount' => $amount,
            ];
        }

        return $allocated;
    }

    /**
     * Equal percentage splits for {@see createTransaction} payloads (same rounding as the Vue equal-split helper).
     *
     * @param  list<int|string>  $userIds  Family user IDs, stable order (e.g. ascending id)
     * @return list<array{user_id: int, share_percentage: float}>
     */
    public static function equalShareSplitData(array $userIds): array
    {
        $ids = array_values(array_map(static fn (mixed $id): int => (int) $id, $userIds));
        $count = count($ids);
        if ($count === 0) {
            return [];
        }
        if ($count === 1) {
            return [['user_id' => $ids[0], 'share_percentage' => 100.0]];
        }

        $base = floor(10000 / $count) / 100;
        $splits = [];
        $sum = 0.0;
        for ($i = 0; $i < $count - 1; $i++) {
            $splits[] = ['user_id' => $ids[$i], 'share_percentage' => $base];
            $sum += $base;
        }
        $splits[] = [
            'user_id' => $ids[$count - 1],
            'share_percentage' => round(100.0 - $sum, 2),
        ];

        return $splits;
    }

    /**
     * Sums the amount field from allocated splits.
     *
     * Useful for verifying that allocate() distributed all funds correctly.
     *
     * @param  array<array{user_id: int, share_percentage: float, amount: float}>  $allocated
     * @return float The sum of all amounts
     */
    public static function sumAmounts(array $allocated): float
    {
        return round(array_sum(array_column($allocated, 'amount')), 2);
    }

    /**
     * Distributes a total amount equally among user IDs.
     *
     * The last user receives the remainder to handle uneven division.
     * Each user's share_percentage is calculated based on equal split.
     *
     * @param  array<int>  $userIds  Array of user IDs to distribute among
     * @param  float  $total  The total amount to distribute
     * @return array<array{user_id: int, share_percentage: float, amount: float}>
     */
    public static function distributeEqually(array $userIds, float $total): array
    {
        if (empty($userIds)) {
            return [];
        }

        $count = count($userIds);
        $sharePercentage = 100.0 / $count;
        $allocated = [];
        $remainingAmount = $total;

        foreach ($userIds as $index => $userId) {
            $isLast = $index === $count - 1;

            if ($isLast) {
                // Last user gets the remainder to ensure total is exact
                $amount = round($remainingAmount, 2);
            } else {
                $amount = round($total / $count, 2);
                $remainingAmount -= $amount;
            }

            $allocated[] = [
                'user_id' => $userId,
                'share_percentage' => $sharePercentage,
                'amount' => $amount,
            ];
        }

        return $allocated;
    }
}

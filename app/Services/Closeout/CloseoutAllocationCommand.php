<?php

namespace App\Services\Closeout;

final class CloseoutAllocationCommand
{
    public function __construct(
        public string $name,
        public string $destinationType,
        public ?int $destinationId,
        public ?string $destinationTitle,
        public ?int $closeoutExpenseCategoryId,
        public string $scope = CloseoutScope::User,
        public ?int $ruleId = null,
    ) {}
}

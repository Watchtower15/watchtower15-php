<?php

declare(strict_types=1);

namespace WatchTower15\Exception;

/** 400 and 422: the request was refused as written. */
class InvalidRequestException extends ApiException
{
    /** @return array<string, mixed> Field errors by field name. */
    public function errors(): array
    {
        $errors = $this->getProblem()['errors'] ?? [];
        /** @var array<string, mixed> $errors */
        $errors = is_array($errors) ? $errors : [];

        return $errors;
    }
}

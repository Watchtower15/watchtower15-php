<?php

declare(strict_types=1);

namespace WatchTower15\Exception;

/** 422 `limit_reached` or `held_limit_reached`. */
class LimitReachedException extends ApiException
{
    public function limit(): ?int
    {
        $limit = $this->getProblem()['limit'] ?? null;

        return is_int($limit) ? $limit : null;
    }

    public function used(): ?int
    {
        $used = $this->getProblem()['used'] ?? null;

        return is_int($used) ? $used : null;
    }
}

<?php

declare(strict_types=1);

namespace WatchTower15\Exception;

/** The log ingest stop: the source's volume limit is reached until `resumesAt()`. */
class LogVolumeLimitException extends RateLimitException
{
    public function resumesAt(): ?\DateTimeImmutable
    {
        $data = $this->getProblem()['data'] ?? null;
        $at = is_array($data) ? ($data['resumes_at'] ?? null) : null;
        if (! is_int($at) && ! (is_string($at) && ctype_digit($at))) {
            return null;
        }
        $ms = (int) $at;

        return (new \DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone(new \DateTimeZone('UTC'));
    }
}

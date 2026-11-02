<?php

declare(strict_types=1);

namespace WatchTower15\Api;

use WatchTower15\Http\ResponseInfo;

/**
 * One page of a list (plan WT15-SDK-00007 D11, D12). `autoPaging()` walks every item of every page, fetching the next page
 * only when the current one is used up and waiting for a spent rate limit's reset when that is close enough.
 *
 * @template-covariant T
 * @implements \IteratorAggregate<int, T>
 */
final class Page implements \IteratorAggregate, \Countable
{
    /**
     * @param list<T> $data
     * @param \Closure(string): Page<T> $fetch
     * @param \Closure(): void $pace
     * @internal built by the runtime
     */
    public function __construct(
        public readonly array $data,
        public readonly ?string $nextCursor,
        public readonly ?object $meta,
        public readonly ?int $total,
        public readonly ?ResponseInfo $response,
        private readonly \Closure $fetch,
        private readonly \Closure $pace,
    ) {
    }

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }

    /**
     * The items of this page only.
     *
     * @return \Traversable<int, T>
     */
    public function getIterator(): \Traversable
    {
        yield from $this->data;
    }

    public function count(): int
    {
        return count($this->data);
    }

    /**
     * Every item of this page and all the pages after it, lazily: the next page is requested only when asked for.
     *
     * @return \Generator<int, T>
     */
    public function autoPaging(): \Generator
    {
        $index = 0;
        $page = $this;
        while (true) {
            foreach ($page->data as $item) {
                yield $index++ => $item;
            }
            if ($page->nextCursor === null) {
                return;
            }
            ($page->pace)();
            $page = ($page->fetch)($page->nextCursor);
        }
    }
}

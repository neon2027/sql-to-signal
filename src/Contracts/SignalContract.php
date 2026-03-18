<?php

namespace Laravelldone\SqlToSignal\Contracts;

use Illuminate\Support\Collection;

interface SignalContract
{
    /** @return Collection<int, mixed> */
    public function getData(): Collection;

    public function getQuery(): string;

    /** @return array<int, mixed> */
    public function getBindings(): array;

    public function getModelClass(): ?string;

    public function refresh(): static;

    /** @return array<string, mixed> */
    public function toArray(): array;

    // ── Pagination ─────────────────────────────────────────────────────────────

    public function isPaginated(): bool;

    public function getTotal(): int;

    public function getPerPage(): int;

    public function getCurrentPage(): int;

    public function getLastPage(): int;

    public function nextPage(): static;

    public function prevPage(): static;

    public function goToPage(int $page): static;
}

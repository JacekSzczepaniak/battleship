<?php

namespace App\Domain\Expedition;

interface IslandCatalog
{
    /** @return list<Island> wyspy w kolejności wyprawy */
    public function all(): array;

    public function byId(string $id): ?Island;
}

<?php

declare(strict_types=1);

namespace Survos\AltoRaw\Contract;

/** Node IDs are scoped to one ALTO document; source IDs remain separate. */
final readonly class NodeIdentity
{
    public function __construct(
        public int $id,
        public ?int $parentId,
        public int $order,
        public string $name,
        public ?string $namespace,
    ) {
    }
}

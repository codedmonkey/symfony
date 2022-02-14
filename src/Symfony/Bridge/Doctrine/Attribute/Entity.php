<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\Doctrine\Attribute;

/**
 * Controller parameter tag to configure entity arguments.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
class Entity
{
    private ?string $entityManager;
    private array $exclude;
    private array $mapping;
    private bool $stripNull;
    private ?string $expr;
    private array|string|null $id;
    private bool $evictCache;

    public function __construct(
        string $entityManager = null,
        array $exclude = [],
        array $mapping = [],
        bool $stripNull = false,
        string $expr = null,
        array|string $id = null,
        bool $evictCache = false,
    ) {
        $this->entityManager = $entityManager;
        $this->exclude = $exclude;
        $this->mapping = $mapping;
        $this->stripNull = $stripNull;
        $this->expr = $expr;
        $this->id = $id;
        $this->evictCache = $evictCache;
    }

    public function getEntityManager(): ?string
    {
        return $this->entityManager;
    }

    public function getExclude(): array
    {
        return $this->exclude;
    }

    public function getMapping(): array
    {
        return $this->mapping;
    }

    public function getStripNull(): bool
    {
        return $this->stripNull;
    }

    public function getExpr(): ?string
    {
        return $this->expr;
    }

    public function getId(): array|string|null
    {
        return $this->id;
    }

    public function getEvictCache(): bool
    {
        return $this->evictCache;
    }
}

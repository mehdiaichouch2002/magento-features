<?php

declare(strict_types=1);

namespace Aichouchm\ProductRelations\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchVersionInterface;

/**
 * Optional: seeds a couple of test relations in dev environments.
 * Safe to skip in production (data patches are idempotent by design).
 */
class InstallSampleRelations implements DataPatchInterface, PatchVersionInterface
{
    public static function getDependencies(): array
    {
        return [];
    }

    public static function getVersion(): string
    {
        return '1.0.0';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        // Intentionally empty — placeholder to demonstrate the pattern.
        // Real implementation would inject the repository and create seed data.
        return $this;
    }
}

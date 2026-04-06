<?php

declare(strict_types=1);

namespace Aichouchm\ProductRelations\Api\Data;

/**
 * Product Relation data contract.
 *
 * Represents a directional relationship between two products
 * (e.g., color variant, size sibling, accessory).
 */
interface ProductRelationInterface
{
    public const ENTITY_ID        = 'entity_id';
    public const PRODUCT_ID       = 'product_id';
    public const RELATED_ID       = 'related_product_id';
    public const RELATION_TYPE    = 'relation_type';
    public const SORT_ORDER       = 'sort_order';
    public const IS_ACTIVE        = 'is_active';
    public const CREATED_AT       = 'created_at';
    public const UPDATED_AT       = 'updated_at';

    // Relation type constants
    public const TYPE_COLOR    = 'color';
    public const TYPE_SIZE     = 'size';
    public const TYPE_SIBLING  = 'sibling';
    public const TYPE_ACCESSORY = 'accessory';

    public function getEntityId(): ?int;

    public function getProductId(): int;
    public function setProductId(int $productId): self;

    public function getRelatedProductId(): int;
    public function setRelatedProductId(int $relatedProductId): self;

    public function getRelationType(): string;
    public function setRelationType(string $relationType): self;

    public function getSortOrder(): int;
    public function setSortOrder(int $sortOrder): self;

    public function getIsActive(): bool;
    public function setIsActive(bool $isActive): self;

    public function getCreatedAt(): ?string;
    public function getUpdatedAt(): ?string;
}

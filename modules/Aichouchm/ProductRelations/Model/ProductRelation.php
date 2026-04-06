<?php

declare(strict_types=1);

namespace Aichouchm\ProductRelations\Model;

use Aichouchm\ProductRelations\Api\Data\ProductRelationInterface;
use Magento\Framework\Model\AbstractModel;

class ProductRelation extends AbstractModel implements ProductRelationInterface
{
    protected $_eventPrefix = 'aichouchm_product_relation';
    protected $_eventObject = 'relation';

    protected function _construct(): void
    {
        $this->_init(ResourceModel\Relation::class);
    }

    public function getEntityId(): ?int
    {
        $value = $this->getData(self::ENTITY_ID);
        return $value !== null ? (int) $value : null;
    }

    public function getProductId(): int
    {
        return (int) $this->getData(self::PRODUCT_ID);
    }

    public function setProductId(int $productId): self
    {
        return $this->setData(self::PRODUCT_ID, $productId);
    }

    public function getRelatedProductId(): int
    {
        return (int) $this->getData(self::RELATED_ID);
    }

    public function setRelatedProductId(int $relatedProductId): self
    {
        return $this->setData(self::RELATED_ID, $relatedProductId);
    }

    public function getRelationType(): string
    {
        return (string) $this->getData(self::RELATION_TYPE);
    }

    public function setRelationType(string $relationType): self
    {
        return $this->setData(self::RELATION_TYPE, $relationType);
    }

    public function getSortOrder(): int
    {
        return (int) $this->getData(self::SORT_ORDER);
    }

    public function setSortOrder(int $sortOrder): self
    {
        return $this->setData(self::SORT_ORDER, $sortOrder);
    }

    public function getIsActive(): bool
    {
        return (bool) $this->getData(self::IS_ACTIVE);
    }

    public function setIsActive(bool $isActive): self
    {
        return $this->setData(self::IS_ACTIVE, $isActive);
    }

    public function getCreatedAt(): ?string
    {
        return $this->getData(self::CREATED_AT);
    }

    public function getUpdatedAt(): ?string
    {
        return $this->getData(self::UPDATED_AT);
    }
}

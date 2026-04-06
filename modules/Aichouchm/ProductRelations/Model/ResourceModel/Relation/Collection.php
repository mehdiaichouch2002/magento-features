<?php

declare(strict_types=1);

namespace Aichouchm\ProductRelations\Model\ResourceModel\Relation;

use Aichouchm\ProductRelations\Model\ProductRelation;
use Aichouchm\ProductRelations\Model\ResourceModel\Relation as RelationResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = RelationResource::PRIMARY_KEY;
    protected $_eventPrefix = 'aichouchm_product_relation_collection';
    protected $_eventObject = 'relation_collection';

    protected function _construct(): void
    {
        $this->_init(ProductRelation::class, RelationResource::class);
    }

    /**
     * Convenience filter: active relations for a product.
     */
    public function filterByProductId(int $productId, string $type = ''): self
    {
        $this->addFieldToFilter('product_id', $productId)
             ->addFieldToFilter('is_active', 1);

        if ($type !== '') {
            $this->addFieldToFilter('relation_type', $type);
        }

        $this->setOrder('sort_order', self::SORT_ORDER_ASC);

        return $this;
    }
}

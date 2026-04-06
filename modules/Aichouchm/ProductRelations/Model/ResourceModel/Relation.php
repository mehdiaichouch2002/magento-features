<?php

declare(strict_types=1);

namespace Aichouchm\ProductRelations\Model\ResourceModel;

use Aichouchm\ProductRelations\Api\Data\ProductRelationInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Relation extends AbstractDb
{
    public const TABLE_NAME  = 'aichouchm_product_relation';
    public const PRIMARY_KEY = 'entity_id';

    protected function _construct(): void
    {
        $this->_init(self::TABLE_NAME, self::PRIMARY_KEY);
    }

    /**
     * Fetch related product IDs for a product by type.
     * Uses a single optimised query — only SELECT the columns we need.
     *
     * @return int[]
     */
    public function getRelatedProductIds(int $productId, string $type = ''): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), [ProductRelationInterface::RELATED_ID])
            ->where(ProductRelationInterface::PRODUCT_ID . ' = ?', $productId)
            ->where(ProductRelationInterface::IS_ACTIVE . ' = ?', 1)
            ->order(ProductRelationInterface::SORT_ORDER . ' ASC');

        if ($type !== '') {
            $select->where(ProductRelationInterface::RELATION_TYPE . ' = ?', $type);
        }

        return array_map('intval', $connection->fetchCol($select));
    }
}

<?php

declare(strict_types=1);

namespace Aichouchm\ProductRelations\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

interface ProductRelationSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \Aichouchm\ProductRelations\Api\Data\ProductRelationInterface[]
     */
    public function getItems(): array;

    /**
     * @param \Aichouchm\ProductRelations\Api\Data\ProductRelationInterface[] $items
     */
    public function setItems(array $items): self;
}

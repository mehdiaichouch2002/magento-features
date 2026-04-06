<?php

declare(strict_types=1);

namespace Aichouchm\ProductRelations\Api;

use Aichouchm\ProductRelations\Api\Data\ProductRelationInterface;
use Aichouchm\ProductRelations\Api\Data\ProductRelationSearchResultsInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

interface ProductRelationRepositoryInterface
{
    /**
     * @throws CouldNotSaveException
     */
    public function save(ProductRelationInterface $relation): ProductRelationInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $entityId): ProductRelationInterface;

    /**
     * Fetch all active relations for a given product, filtered by type.
     *
     * @param int    $productId
     * @param string $type      One of ProductRelationInterface::TYPE_* constants
     * @return ProductRelationInterface[]
     */
    public function getByProductId(int $productId, string $type = ''): array;

    public function getList(SearchCriteriaInterface $searchCriteria): ProductRelationSearchResultsInterface;

    /**
     * @throws CouldNotDeleteException
     */
    public function delete(ProductRelationInterface $relation): bool;

    /**
     * @throws CouldNotDeleteException
     * @throws NoSuchEntityException
     */
    public function deleteById(int $entityId): bool;
}

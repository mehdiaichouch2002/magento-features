<?php

declare(strict_types=1);

namespace Aichouchm\ProductRelations\Model;

use Aichouchm\ProductRelations\Api\Data\ProductRelationInterface;
use Aichouchm\ProductRelations\Api\Data\ProductRelationSearchResultsInterface;
use Aichouchm\ProductRelations\Api\ProductRelationRepositoryInterface;
use Aichouchm\ProductRelations\Model\ResourceModel\Relation as RelationResource;
use Aichouchm\ProductRelations\Model\ResourceModel\Relation\CollectionFactory;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

class ProductRelationRepository implements ProductRelationRepositoryInterface
{
    /**
     * In-memory identity map: avoids duplicate DB reads within a request.
     *
     * @var ProductRelationInterface[]
     */
    private array $cache = [];

    public function __construct(
        private readonly RelationResource $resource,
        private readonly ProductRelationFactory $modelFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly SearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor,
    ) {}

    public function save(ProductRelationInterface $relation): ProductRelationInterface
    {
        try {
            $this->resource->save($relation);
            // Invalidate cache entry so next read is fresh.
            unset($this->cache[$relation->getEntityId()]);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save relation: %1', $e->getMessage()), $e);
        }

        return $relation;
    }

    public function getById(int $entityId): ProductRelationInterface
    {
        if (!isset($this->cache[$entityId])) {
            $model = $this->modelFactory->create();
            $this->resource->load($model, $entityId);

            if (!$model->getEntityId()) {
                throw new NoSuchEntityException(
                    __('Product relation with ID "%1" does not exist.', $entityId)
                );
            }

            $this->cache[$entityId] = $model;
        }

        return $this->cache[$entityId];
    }

    public function getByProductId(int $productId, string $type = ''): array
    {
        // Delegate to the resource model for the lightweight optimised query.
        $ids = $this->resource->getRelatedProductIds($productId, $type);

        // Return raw IDs — caller decides whether to load full products.
        // Keeping this lean prevents N+1 product loads.
        return $ids;
    }

    public function getList(SearchCriteriaInterface $searchCriteria): ProductRelationSearchResultsInterface
    {
        $collection = $this->collectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        /** @var ProductRelationSearchResultsInterface $searchResults */
        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }

    public function delete(ProductRelationInterface $relation): bool
    {
        try {
            unset($this->cache[$relation->getEntityId()]);
            $this->resource->delete($relation);
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(
                __('Could not delete relation with ID %1: %2', $relation->getEntityId(), $e->getMessage()),
                $e
            );
        }

        return true;
    }

    public function deleteById(int $entityId): bool
    {
        return $this->delete($this->getById($entityId));
    }
}

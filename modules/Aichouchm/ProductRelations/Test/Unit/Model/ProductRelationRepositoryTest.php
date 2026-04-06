<?php

declare(strict_types=1);

namespace Aichouchm\ProductRelations\Test\Unit\Model;

use Aichouchm\ProductRelations\Model\ProductRelation;
use Aichouchm\ProductRelations\Model\ProductRelationFactory;
use Aichouchm\ProductRelations\Model\ProductRelationRepository;
use Aichouchm\ProductRelations\Model\ResourceModel\Relation as RelationResource;
use Aichouchm\ProductRelations\Model\ResourceModel\Relation\CollectionFactory;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProductRelationRepositoryTest extends TestCase
{
    private RelationResource&MockObject $resource;
    private ProductRelationFactory&MockObject $modelFactory;
    private ProductRelation&MockObject $model;
    private ProductRelationRepository $repository;

    protected function setUp(): void
    {
        $this->resource     = $this->createMock(RelationResource::class);
        $this->modelFactory = $this->createMock(ProductRelationFactory::class);
        $this->model        = $this->createMock(ProductRelation::class);

        $this->modelFactory->method('create')->willReturn($this->model);

        $this->repository = new ProductRelationRepository(
            $this->resource,
            $this->modelFactory,
            $this->createMock(CollectionFactory::class),
            $this->createMock(SearchResultsInterfaceFactory::class),
            $this->createMock(CollectionProcessorInterface::class),
        );
    }

    public function testGetByIdThrowsWhenNotFound(): void
    {
        $this->model->method('getEntityId')->willReturn(null);

        $this->expectException(NoSuchEntityException::class);
        $this->repository->getById(999);
    }

    public function testGetByIdReturnsCachedModel(): void
    {
        $this->model->method('getEntityId')->willReturn(1);

        // resource->load should only be called once even if getById called twice
        $this->resource->expects($this->once())->method('load');

        $this->repository->getById(1);
        $this->repository->getById(1);
    }

    public function testSaveInvalidatesCacheEntry(): void
    {
        $this->model->method('getEntityId')->willReturn(1);

        // Prime the cache
        $this->resource->method('load')->willReturnSelf();
        $this->repository->getById(1);

        // Save should invalidate the entry
        $this->resource->expects($this->once())->method('save');
        $this->repository->save($this->model);

        // Next getById should call load again
        $this->resource->expects($this->once())->method('load');
        $this->repository->getById(1);
    }
}

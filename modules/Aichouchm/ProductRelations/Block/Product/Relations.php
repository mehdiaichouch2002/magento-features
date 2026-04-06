<?php

declare(strict_types=1);

namespace Aichouchm\ProductRelations\Block\Product;

use Aichouchm\ProductRelations\Api\Data\ProductRelationInterface;
use Aichouchm\ProductRelations\Api\ProductRelationRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Block\Product\AbstractProduct;
use Magento\Catalog\Block\Product\Context;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Frontend block: renders related products for the current PDP.
 *
 * Cache strategy: Magento full-page cache will capture this block.
 * The cache key includes the current product ID so each PDP gets its own entry.
 */
class Relations extends AbstractProduct
{
    private const XML_PATH_ENABLED      = 'aichouchm_product_relations/general/enabled';
    private const XML_PATH_CACHE_LIFE   = 'aichouchm_product_relations/general/cache_lifetime';
    private const XML_PATH_MAX_ITEMS    = 'aichouchm_product_relations/display/max_items';

    /** @var Product[]|null  Lazy-loaded, never re-queried within the same request */
    private ?array $relatedProducts = null;

    public function __construct(
        Context $context,
        private readonly ProductRelationRepositoryInterface $relationRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ScopeConfigInterface $scopeConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Returns related products for the current PDP product.
     * Loads only once per request (lazy + identity-map pattern).
     *
     * @return Product[]
     */
    public function getRelatedProducts(): array
    {
        if ($this->relatedProducts !== null) {
            return $this->relatedProducts;
        }

        $currentProduct = $this->getProduct();
        if (!$currentProduct || !$currentProduct->getId()) {
            return $this->relatedProducts = [];
        }

        $relatedIds = $this->relationRepository->getByProductId(
            (int) $currentProduct->getId(),
            ProductRelationInterface::TYPE_SIBLING
        );

        if (empty($relatedIds)) {
            return $this->relatedProducts = [];
        }

        $maxItems = (int) $this->scopeConfig->getValue(
            self::XML_PATH_MAX_ITEMS,
            ScopeInterface::SCOPE_STORE
        );

        $relatedIds = array_slice($relatedIds, 0, $maxItems ?: 10);

        $products = [];
        foreach ($relatedIds as $productId) {
            try {
                $products[] = $this->productRepository->getById($productId);
            } catch (\Magento\Framework\Exception\NoSuchEntityException) {
                // Relation points to a deleted product — skip silently.
            }
        }

        return $this->relatedProducts = $products;
    }

    // ─── Block cache ─────────────────────────────────────────────────────────

    public function getCacheLifetime(): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_CACHE_LIFE,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getCacheKeyInfo(): array
    {
        return [
            'AICHOUCHM_PRODUCT_RELATIONS',
            $this->_storeManager->getStore()->getId(),
            $this->getProduct()?->getId() ?? 0,
        ];
    }
}

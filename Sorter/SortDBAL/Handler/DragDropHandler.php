<?php
/**
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Shopware\SwagCustomSort\Sorter\SortDBAL\Handler;

use Shopware\Bundle\SearchBundle\SortingInterface;
use Shopware\Bundle\SearchBundleDBAL\QueryBuilder;
use Shopware\Bundle\SearchBundleDBAL\SortingHandlerInterface;
use Shopware\Bundle\StoreFrontBundle\Struct\ShopContextInterface;
use Shopware\Components\Model\ModelManager;
use Shopware\Models\Search\CustomSorting;
use Shopware\SwagCustomSort\Components\Listing;
use Shopware\SwagCustomSort\Components\Sorting;
use Shopware\SwagCustomSort\Sorter\Sort\DragDropSorting;

class DragDropHandler implements SortingHandlerInterface
{
    const SORTING_STOCK_ASC = 9;
    const SORTING_STOCK_DESC = 10;

    /**
     * @var Sorting
     */
    private $sortingComponent;

    /**
     * @var ModelManager
     */
    private $modelManager;

    /**
     * @param Sorting      $sortingComponent
     * @param ModelManager $modelManager
     */
    public function __construct(Sorting $sortingComponent, ModelManager $modelManager)
    {
        $this->sortingComponent = $sortingComponent;
        $this->modelManager = $modelManager;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsSorting(SortingInterface $sorting)
    {
        return $sorting instanceof DragDropSorting;
    }

    /**
     * {@inheritdoc}
     */
    public function generateSorting(SortingInterface $sorting, QueryBuilder $query, ShopContextInterface $context)
    {
        /** @var Listing $categoryComponent */
        $categoryComponent = Shopware()->Container()->get('swagcustomsort.listing_component');
        $categoryId = Shopware()->Front()->Request()->getParam('sCategory');
        $linkedCategoryId = $categoryComponent->getLinkedCategoryId($categoryId);
        $hasCustomSort = $categoryComponent->hasCustomSort($categoryId);
        $baseSort = $categoryComponent->getCategoryBaseSort($categoryId);
        if ($hasCustomSort || $baseSort > 0) {
            $baseSorting = $categoryComponent->getCategoryBaseSort($categoryId);
        } else {
            $baseSorting = Shopware()->Config()->get('defaultListingSorting');
        }

        //apply 'plugin' order
        if (($productCategoryAlias = $this->getProductCategoryAlias($query)) !== null) {
            if ($linkedCategoryId) {
                $query->leftJoin(
                    $productCategoryAlias,
                    's_products_sort',
                    'customSort',
                    'customSort.productId = ' . $productCategoryAlias . '.articleID AND (customSort.categoryId = :sortCategoryId OR customSort.categoryId IS NULL)'
                );
                $query->setParameter('sortCategoryId', $linkedCategoryId);
            } else {
                $query->leftJoin(
                    $productCategoryAlias,
                    's_products_sort',
                    'customSort',
                    'customSort.productId = ' . $productCategoryAlias . '.articleID AND (customSort.categoryId = ' . $productCategoryAlias . '.categoryID OR customSort.categoryId IS NULL)'
                );
            }
        }

        //exclude passed products ids from result
        $sortedProductsIds = $this->sortingComponent->getSortedProductsIds();
        if ($sortedProductsIds) {
            $query->andWhere($query->expr()->notIn('product.id', $sortedProductsIds));
        }

        //for records with no 'plugin' order data use the default shopware order
        $handlerData = $this->getDefaultData($baseSorting);
        if ($handlerData) {
            $sorting->setDirection($handlerData['direction']);
            $handlerData['handler']->generateSorting($sorting, $query, $context);
        }
    }

    /**
     * @param $defaultSort
     *
     * @throws \RuntimeException
     *
     * @return array
     */
    private function getDefaultData($defaultSort)
    {
        try {
            /** @var CustomSorting $customSorting */
            $customSorting = $this->modelManager->find(CustomSorting::class, $defaultSort);
        } catch (\Exception $exception) {
            throw new \RuntimeException('No matching sort found', 0, $exception);
        }

        if (!$customSorting instanceof CustomSorting) {
            throw new \RuntimeException('No matching sort found');
        }

        $sortings = json_decode($customSorting->getSortings(), true);

        if (empty($sortings)) {
            throw new \RuntimeException('No matching sort found');
        }

        $direction = reset($sortings)['direction'];
        $sortingClass = key($sortings);
        /** @var SortingInterface $sorting */
        $sorting = new $sortingClass($direction);
        $handler = $this->getSortingHandler($sorting);

        return [
            'handler' => $handler,
            'direction' => $direction,
        ];
    }

    /**
     * @param SortingInterface $sorting
     *
     * @throws \RuntimeException
     *
     * @return SortingHandlerInterface
     */
    private function getSortingHandler(SortingInterface $sorting)
    {
        /** @var SortingHandlerInterface[] $sortingHandlers */
        $sortingHandlers = Shopware()->Container()->get('shopware_searchdbal.sorting_handlers');

        foreach ($sortingHandlers as $handler) {
            if ($handler->supportsSorting($sorting)) {
                return $handler;
            }
        }

        throw new \RuntimeException(sprintf('Sorting %s not supported', get_class($sorting)));
    }

    /**
     * @param QueryBuilder $query
     *
     * @return string|null
     */
    private function getProductCategoryAlias(QueryBuilder $query)
    {
        $joins = $query->getQueryPart('join');

        if (!array_key_exists('product', $joins)) {
            return null;
        }

        foreach ($joins['product'] as $join) {
            if (strpos($join['joinAlias'], 'productCategory') !== 0) {
                continue;
            }

            return $join['joinAlias'];
        }

        return null;
    }
}

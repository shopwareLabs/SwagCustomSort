<?php
/**
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Shopware\CustomModels\CustomSort\CustomSortRepository;
use Shopware\CustomModels\CustomSort\ProductSort;
use Shopware\Models\Article\Article;
use Shopware\Models\Attribute\Category as CategoryAttributes;
use Shopware\Models\Category\Category;
use Shopware\SwagCustomSort\Components\Sorting;

class Shopware_Controllers_Backend_CustomSort extends Shopware_Controllers_Backend_ExtJs
{
    /**
     * @var array
     */
    protected $categoryIdCollection = [];

    /**
     * @var CustomSortRepository
     */
    private $sortRepo;

    /**
     * @var \Enlight_Components_Db_Adapter_Pdo_Mysql
     */
    private $db;

    /**
     * References the shopware config object
     *
     * @var \Shopware_Components_Config
     */
    private $config;

    /**
     * @var \Enlight_Event_EventManager
     */
    private $events;

    public function preDispatch()
    {
        parent::preDispatch();

        $categoryId = (int) $this->Request()->getParam('categoryId');
        $this->generateCategoryAttribute($categoryId);
    }

    /**
     * Get product list and images for current category
     */
    public function getProductListAction()
    {
        $categoryId = (int) $this->Request()->getParam('categoryId');
        $page = (int) $this->Request()->getParam('page');
        $limit = (int) $this->Request()->getParam('limit');
        $offset = (int) $this->Request()->getParam('start');

        $defaultSort = $this->getConfig()->get('defaultListingSorting');
        $sort = (int) $this->Request()->getParam('sortBy', $defaultSort);

        try {
            /** @var Sorting $sorting */
            $sorting = Shopware()->Container()->get('swagcustomsort.sorting_component');

            $sortedProducts = $this->getSortRepository()->getSortedProducts($categoryId);
            $sorting->setSortedProducts($sortedProducts);

            $sortedProductsIds = $sorting->getSortedProductsIds();
            $newOffset = $sorting->getOffset($offset, $page, $limit);
            $builder = $this->getSortRepository()
                ->getProductImageQuery($categoryId, $sortedProductsIds, $sort, $newOffset, $limit);

            $countBuilder = $this->getSortRepository()->getProductImageCountQuery($categoryId);
            $total = $countBuilder->execute()->fetch();

            $getUnsortedProducts = $builder->execute()->fetchAll();
            $result = $sorting->sortProducts($getUnsortedProducts, $offset, $limit);

            $result = array_map(function ($resultElement) {
                $resultElement['path'] = $this->getMediaPath(
                    'media/image/thumbnail/' . $resultElement['path'] . '_140x140.' . $resultElement['extension']
                );

                return $resultElement;
            }, $result);
            $this->View()->assign(['success' => true, 'data' => $result, 'total' => $total['Total']]);
        } catch (\Exception $ex) {
            $this->View()->assign(['success' => false, 'message' => $ex->getMessage()]);
        }
    }

    /**
     * Get settings for current category
     */
    public function getCategorySettingsAction()
    {
        $categoryId = (int) $this->Request()->getParam('categoryId');
        $defaultSort = $this->getConfig()->get('defaultListingSorting');

        $data = [
            'id' => null,
            'defaultSort' => 0,
            'categoryLink' => 0,
            'baseSort' => $defaultSort,
        ];

        /** @var CategoryAttributes $categoryAttributes */
        $categoryAttributes = $this->getModelManager()->getRepository(CategoryAttributes::class)
            ->findOneBy(['categoryId' => $categoryId]);
        if ($categoryAttributes) {
            $baseSort = $categoryAttributes->getSwagBaseSort();
            if ($baseSort > 0) {
                $defaultSort = $baseSort;
            }

            $data = [
                'id' => null,
                'defaultSort' => $categoryAttributes->getSwagShowByDefault(),
                'categoryLink' => $categoryAttributes->getSwagLink(),
                'baseSort' => $defaultSort,
            ];
        }

        $this->View()->assign(['success' => true, 'data' => $data]);
    }

    /**
     * Save category settings for current category
     */
    public function saveCategorySettingsAction()
    {
        $categoryId = (int) $this->Request()->getParam('categoryId');
        $categoryLink = (int) $this->Request()->getParam('categoryLink');
        $defaultSort = (int) $this->Request()->getParam('defaultSort');
        $baseSort = (int) $this->Request()->getParam('baseSort');

        try {
            $this->getSortRepository()->updateCategoryAttributes($categoryId, $baseSort, $categoryLink, $defaultSort);

            $this->View()->assign(['success' => true]);
        } catch (\Exception $ex) {
            $this->View()->assign(['success' => false, 'message' => $ex->getMessage()]);
        }
    }

    /**
     * Save product list after product reorder
     */
    public function saveProductListAction()
    {
        $movedProducts = $this->Request()->getParam('products');
        if (empty($movedProducts)) {
            return;
        }

        if ($movedProducts['productId']) {
            $movedProducts = [$movedProducts];
        }

        $categoryId = (int) $this->Request()->getParam('categoryId');
        $movedProducts = $this->prepareKeys($movedProducts);
        $offset = $this->getOffset($movedProducts, $categoryId);
        $length = $this->getLength($movedProducts, $offset, $categoryId);
        $defaultSort = $this->getConfig()->get('defaultListingSorting');
        $sort = (int) $this->Request()->getParam('sortBy', $defaultSort);

        //get all products
        $sorting = Shopware()->Container()->get('swagcustomsort.sorting_component');

        //Get all sorted products for current category and set them in components for further sorting
        $allSortedProducts = $this->getSortRepository()->getSortedProducts($categoryId);
        $sorting->setSortedProducts($allSortedProducts);

        //Get unsorted products for current category
        $sortedProductsIds = $sorting->getSortedProductsIds();
        $builder = $this->getSortRepository()->getProductImageQuery($categoryId, $sortedProductsIds, $sort);
        $getProducts = $builder->execute()->fetchAll();

        //Return result with proper position of all products
        $getAllProducts = $sorting->sortProducts($getProducts, $offset, $length);

        //check for deleted products
        $deletedPosition = $this->getSortRepository()->getPositionOfDeletedProduct($categoryId);
        if ($deletedPosition !== null) {
            $getAllProducts = $this->fixDeletedPosition((int) $deletedPosition, $getAllProducts);
        }

        //get sorted products
        $sortedProducts = $this->applyNewPosition($getAllProducts, $movedProducts, $offset);

        //get sql values needed for update query
        $sqlValues = $this->getSQLValues($sortedProducts, $categoryId);

        //update positions
        $sql = 'REPLACE INTO s_products_sort (id, categoryId, productId, position, pin) VALUES '
            . rtrim($sqlValues, ',');
        $this->getDB()->query($sql);

        //reset deleted product flag
        $this->getSortRepository()->resetDeletedPosition($categoryId);

        //after update check for unnecessary records (delete all records to the last pin product)
        $this->getSortRepository()->deleteUnpinnedRecords($categoryId);

        //set current product's cache as invalid
        $this->invalidateProductCache($movedProducts);

        $this->View()->assign(['success' => true]);
    }

    /**
     * Unpin product
     */
    public function unpinProductAction()
    {
        $product = $this->Request()->getParam('products');
        $sortId = (int) $product['positionId'];

        try {
            if (!$sortId) {
                throw new RuntimeException("Unpin product '{$product['name']}' with id '{$product['id']}', failed!");
            }

            $categoryId = (int) $this->Request()->getParam('categoryId');

            $this->getSortRepository()->unpinById($sortId);

            $this->getSortRepository()->deleteUnpinnedRecords($categoryId);

            $this->View()->assign(['success' => true]);
        } catch (\Exception $ex) {
            $this->View()->assign(['success' => false, 'message' => $ex->getMessage()]);
        }
    }

    /**
     * Remove product from current and child categories.
     */
    public function removeProductAction()
    {
        $productId = (int) $this->Request()->get('productId');
        $categoryId = (int) $this->Request()->get('categoryId');

        /** @var Category $category */
        $category = Shopware()->Models()->getReference(Category::class, $categoryId);
        if ($category) {
            $this->collectCategoryIds($category);

            /** @var Article $product */
            $product = Shopware()->Models()->getReference(Article::class, $productId);
            $product->removeCategory($category);

            foreach ($this->categoryIdCollection as $childCategoryId) {
                /** @var Category $childCategoryModel */
                $childCategoryModel = Shopware()->Models()
                    ->getReference(Category::class, $childCategoryId);
                if ($childCategoryModel) {
                    $product->removeCategory($childCategoryModel);
                }
            }

            Shopware()->Models()->flush();
        }

        $this->View()->assign(['success' => true]);
    }

    /**
     * Generates an empty attribute for a category if none exists
     *
     * @param int $categoryId
     */
    private function generateCategoryAttribute($categoryId)
    {
        try {
            /** @var Category $category */
            $category = $this->getModelManager()->find(Category::class, $categoryId);
        } catch (\Exception $exception) {
            return;
        }

        if ($category === null || $category->getAttribute() !== null) {
            return;
        }

        try {
            $attribute = new \Shopware\Models\Attribute\Category();
            $this->getModelManager()->persist($attribute);
            $category->setAttribute($attribute);
            $this->getModelManager()->flush($category);
        } catch (\Exception $exception) {
            return;
        }
    }

    /**
     * Returns sort repository
     *
     * @return CustomSortRepository
     */
    private function getSortRepository()
    {
        if ($this->sortRepo === null) {
            $this->sortRepo = $this->getModelManager()->getRepository(ProductSort::class);
        }

        return $this->sortRepo;
    }

    /**
     * Returns pdo mysql db adapter instance
     *
     * @return \Enlight_Components_Db_Adapter_Pdo_Mysql
     */
    private function getDB()
    {
        if ($this->db === null) {
            $this->db = Shopware()->Db();
        }

        return $this->db;
    }

    /**
     * Returns config instance
     *
     * @return \Shopware_Components_Config
     */
    private function getConfig()
    {
        if ($this->config === null) {
            $this->config = Shopware()->Config();
        }

        return $this->config;
    }

    /**
     * @return \Enlight_Event_EventManager
     */
    private function getEvents()
    {
        if ($this->events === null) {
            $this->events = Shopware()->Events();
        }

        return $this->events;
    }

    /**
     * Insert category id to category ids collection.
     *
     * @param $categoryIdCollection
     */
    private function setCategoryIdCollection($categoryIdCollection)
    {
        $this->categoryIdCollection[] = $categoryIdCollection;
    }

    /**
     * Convert a given virtual media path to its real URL used in the
     * media repository for Shopware versions >= 5.1.0 (which introduced the
     * MediaService).
     * For older versions the path matches the filesystem structure.
     *
     * @param string $path
     *
     * @return string
     */
    private function getMediaPath($path)
    {
        if (version_compare(Shopware()->Config()->get('Version'), '5.1', '<')) {
            return $path;
        }
        /** @var Shopware\Bundle\MediaBundle\MediaService $mediaService */
        $mediaService = $this->get('shopware_media.media_service');

        return $mediaService->getUrl($path);
    }

    /**
     * Apply new positions of the products
     *
     * @param array $allProducts - all products contained in the current category
     * @param array $products    - the selected products, that were dragged
     * @param int   $index       - the id of offset products
     *
     * @return array $result
     */
    private function applyNewPosition($allProducts, $products, $index)
    {
        $allProducts = $this->prepareKeys($allProducts);
        $products = $this->prepareKeys($products);

        //apply new positions for the products
        $result = [];
        foreach ($products as $productData) {
            $newPosition = $productData['position'];
            $oldPosition = $productData['oldPosition'];

            $result[$newPosition] = $productData;
            $result[$newPosition]['position'] = $newPosition;
            $result[$newPosition]['oldPosition'] = $oldPosition;
        }

        foreach ($allProducts as $id => &$product) {
            if (array_key_exists($id, $products)) {
                continue;
            }

            while (array_key_exists($index, $result)) {
                ++$index;
            }

            $result[$index] = $product;
            $result[$index]['position'] = $index;
            $result[$index]['oldPosition'] = $index;

            ++$index;
        }

        return $result;
    }

    /**
     * Returns sql values for update query
     *
     * @param array $productsForUpdate
     * @param int   $categoryId
     *
     * @return string - values for update
     */
    private function getSQLValues($productsForUpdate, $categoryId)
    {
        $sqlValues = '';
        foreach ($productsForUpdate as $newProduct) {
            if ($newProduct['productId'] > 0 && $newProduct['pin'] > 0) {
                $sqlValues .= "('" . $newProduct['positionId'] . "', '"
                    . $categoryId . "', '"
                    . $newProduct['productId'] . "', '"
                    . $newProduct['position'] . "', '"
                    . $newProduct['pin'] . "'),";
            }
        }

        return $sqlValues;
    }

    /**
     * @param array $products
     *
     * @return array
     */
    private function prepareKeys(array $products)
    {
        $result = [];
        foreach ($products as $product) {
            $result[$product['productId']] = $product;
        }

        return $result;
    }

    /**
     * Helper function, for getting a part of the array, which contains all products.
     * Returns the offset from which the new array should start.
     *
     * @param array $products   - selected products
     * @param int   $categoryId
     *
     * @return int - the smallest position
     */
    private function getOffset($products, $categoryId)
    {
        $offset = null;
        foreach ($products as $productData) {
            $newPosition = $productData['position'];
            $oldPosition = $productData['oldPosition'];

            if ($offset > min($newPosition, $oldPosition) || $offset === null) {
                $offset = min($newPosition, $oldPosition);
            }
        }

        $maxPosition = $this->getSortRepository()->getMaxPosition($categoryId);
        if ($maxPosition === null) {
            return 0;
        }

        //checks for deleted products
        $deletedPosition = $this->getSortRepository()->getPositionOfDeletedProduct($categoryId);
        if ($deletedPosition !== null) {
            $offset = min($offset, ++$maxPosition, $deletedPosition);
        } else {
            $offset = min($offset, ++$maxPosition);
        }

        return $offset;
    }

    /**
     * Helper function, for getting a part of the array, which contains all products.
     * Returns the length of the new array.
     *
     * @param array $products
     * @param int   $offset
     * @param int   $categoryId
     *
     * @return int - the length of the new array
     */
    private function getLength($products, $offset, $categoryId)
    {
        $length = null;
        foreach ($products as $productData) {
            $newPosition = $productData['position'];
            $oldPosition = $productData['oldPosition'];

            if ($length < max($newPosition, $oldPosition) || $length === null) {
                $length = max($newPosition, $oldPosition);
            }
        }

        //checks for deleted products
        $deletedPosition = $this->getSortRepository()->getPositionOfDeletedProduct($categoryId);
        if ($deletedPosition !== null) {
            $maxPosition = $this->getSortRepository()->getMaxPosition($categoryId);
            $length = max($length, $maxPosition);
        }

        $length = ($length - $offset) + 1;

        return $length;
    }

    /**
     * @param int   $deletedPosition
     * @param array $allProducts
     *
     * @return array
     */
    private function fixDeletedPosition($deletedPosition, array $allProducts)
    {
        $index = $deletedPosition;
        foreach ($allProducts as &$product) {
            if ($product['position'] < $deletedPosition) {
                continue;
            }

            if ($product['position'] === null) {
                break;
            }

            $product['position'] = $index++;
        }

        return $allProducts;
    }

    /**
     * @param array $movedProducts
     */
    private function invalidateProductCache(array $movedProducts)
    {
        //Invalidate the cache for the current product
        foreach ($movedProducts as $product) {
            $this->getEvents()->notify(
                'Shopware_Plugins_HttpCache_InvalidateCacheId',
                ['cacheId' => "a{$product['id']}"]
            );
        }
    }

    /**
     * Check current category for child categories and
     * add ids to collection.
     *
     * @param Category $categoryModel
     */
    private function collectCategoryIds($categoryModel)
    {
        $categoryId = $categoryModel->getId();
        $this->setCategoryIdCollection($categoryId);

        $sql = 'SELECT id FROM s_categories WHERE path LIKE ?';
        $categories = Shopware()->Db()->fetchAll($sql, ['%|' . $categoryId . '|%']);

        if (!$categories) {
            return;
        }

        foreach ($categories as $categoryId) {
            $this->setCategoryIdCollection($categoryId);
        }
    }
}

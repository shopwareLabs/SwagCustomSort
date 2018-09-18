<?php
/**
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Shopware\SwagCustomSort\Components;

use Shopware\Components\Model\ModelManager;
use Shopware\CustomModels\CustomSort\ProductSort;
use Shopware\Models\Attribute\Category as CategoryAttributes;
use Shopware\Models\Category\Category;
use Shopware_Components_Config as Config;

class Listing
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var \Shopware\Components\Model\ModelRepository
     */
    private $categoryAttributesRepo;

    /**
     * @var \Shopware\Models\Category\Repository
     */
    private $categoryRepo;

    /**
     * @var \Shopware\CustomModels\CustomSort\CustomSortRepository
     */
    private $customSortRepo;

    /**
     * @param Config       $config
     * @param ModelManager $modelManager
     */
    public function __construct(Config $config, ModelManager $modelManager)
    {
        $this->config = $config;

        $this->categoryAttributesRepo = $modelManager->getRepository(CategoryAttributes::class);
        $this->categoryRepo = $modelManager->getRepository(Category::class);
        $this->customSortRepo = $modelManager->getRepository(ProductSort::class);
    }

    /**
     * @param $categoryId
     *
     * @return bool
     */
    public function showCustomSortName($categoryId)
    {
        $sortName = $this->getFormattedSortName();
        if (empty($sortName)) {
            return false;
        }

        $hasCustomSort = $this->hasCustomSort($categoryId);
        if ($hasCustomSort) {
            return true;
        }

        return false;
    }

    /**
     * @return string
     */
    public function getFormattedSortName()
    {
        $formattedName = $this->config->get('swagCustomSortName');

        return trim($formattedName);
    }

    /**
     * @param $categoryId
     *
     * @return bool
     */
    public function hasCustomSort($categoryId)
    {
        $isLinked = $this->isLinked($categoryId);
        if ($isLinked) {
            return true;
        }

        $hasOwnSort = $this->hasOwnSort($categoryId);
        if ($hasOwnSort) {
            return true;
        }

        return false;
    }

    /**
     * Checks whether this category has to use its custom sort by default, e.g. on category load use this custom sort
     *
     * @param $categoryId
     *
     * @return bool
     */
    public function showCustomSortAsDefault($categoryId)
    {
        /* @var CategoryAttributes $categoryAttributes */
        $categoryAttributes = $this->categoryAttributesRepo->findOneBy(['categoryId' => $categoryId]);
        if (!$categoryAttributes instanceof CategoryAttributes) {
            return false;
        }

        $useDefaultSort = (bool) $categoryAttributes->getSwagShowByDefault();
        $hasOwnSort = $this->hasOwnSort($categoryId);
        $baseSort = $this->getCategoryBaseSort($categoryId);
        if ($useDefaultSort && ($hasOwnSort || $baseSort > 0)) {
            return true;
        }

        return false;
    }

    /**
     * Returns the id of the linked category.
     *
     * @param $categoryId
     *
     * @return int
     */
    public function getLinkedCategoryId($categoryId)
    {
        /* @var CategoryAttributes $categoryAttributes */
        $categoryAttributes = $this->categoryAttributesRepo->findOneBy(['categoryId' => $categoryId]);
        if (!$categoryAttributes instanceof CategoryAttributes) {
            return false;
        }

        $linkedCategoryId = $categoryAttributes->getSwagLink();
        if ($linkedCategoryId === null) {
            return false;
        }

        /* @var Category $category */
        $category = $this->categoryRepo->find($linkedCategoryId);
        if (!$category instanceof Category) {
            return false;
        }

        return $linkedCategoryId;
    }

    /**
     * Returns the base sort id for selected category
     *
     * @param $categoryId
     *
     * @return bool
     */
    public function getCategoryBaseSort($categoryId)
    {
        $defaultListingSorting = Shopware()->Config()->get('defaultListingSorting');

        /* @var CategoryAttributes $categoryAttributes */
        $categoryAttributes = $this->categoryAttributesRepo->findOneBy(['categoryId' => $categoryId]);
        if (!$categoryAttributes instanceof CategoryAttributes) {
            return $defaultListingSorting;
        }

        $baseSortId = $categoryAttributes->getSwagBaseSort();
        if ($baseSortId === null) {
            return $defaultListingSorting;
        }

        return $baseSortId;
    }

    /**
     * @param $categoryId
     *
     * @return bool
     */
    private function isLinked($categoryId)
    {
        /* @var CategoryAttributes $categoryAttributes */
        $categoryAttributes = $this->categoryAttributesRepo->findOneBy(['categoryId' => $categoryId]);
        if (!$categoryAttributes instanceof CategoryAttributes) {
            return false;
        }

        $linkedCategoryId = $categoryAttributes->getSwagLink();
        if ($linkedCategoryId === null) {
            return false;
        }

        /* @var Category $category */
        $category = $this->categoryRepo->find($linkedCategoryId);
        if (!$category instanceof Category) {
            return false;
        }

        return true;
    }

    /**
     * Checks whether this category has own custom sort
     *
     * @param $categoryId
     *
     * @return bool
     */
    private function hasOwnSort($categoryId)
    {
        return $this->customSortRepo->hasCustomSort($categoryId);
    }
}

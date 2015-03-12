<?php

namespace Shopware\SwagCustomSort\Components;

class Listing
{
    /**
     * @var Shopware_Components_Config
     */
    private $config = null;

    /**
     * @var /Shopware\Components\Model\ModelManager
     */
    private $em = null;

    private $categoryAttributesRepo = null;

    private $categoryRepo = null;

    private $customSortRepo = null;

    private $categoryId = null;

    public function __construct(Shopware_Components_Config $config, \Shopware\Components\Model\ModelManager $em, int $categoryId) {
        $this->config = $config;
        $this->em = $em;
        $this->categoryId = $categoryId;
    }

    public function getConfig()
    {
        return $this->config;
    }

    public function getEntityManager()
    {
        return $this->em;
    }

    public function getCategoryAttributesRepository()
    {
        if ($this->categoryAttributesRepo === null) {
            $this->categoryAttributesRepo = $this->getEntityManager()->getRepository('Shopware\Models\Attribute\Category');
        }

        return $this->categoryAttributesRepo;
    }

    public function getCategoryRepository()
    {
        if ($this->categoryRepo === null) {
            $this->categoryRepo = $this->getEntityManager()->getRepository('Shopware\Models\Category\Category');
        }

        return $this->categoryRepo;
    }

    public function getCustomSortRepository()
    {
        if ($this->customSortRepo === null) {
            $this->customSortRepo = $this->getEntityManager()->getRepository('Shopware\CustomModels\CustomSort\ArticleSort');
        }

        return $this->customSortRepo;
    }

    public function getCategoryId()
    {
        return $this->categoryId;
    }

    public function showCustomSortName()
    {
        $sortName = $this->getFormattedSortName();
        if (empty($sortName)) {
            return false;
        }

        $hasCustomSort = $this->hasCustomSort();
        if ($hasCustomSort) {
            return true;
        }

        return false;
    }

    public function getFormattedSortName()
    {
        $formattedName = $this->getSortName();

        return trim($formattedName);
    }

    public function getSortName()
    {
        $name = $this->getConfig()->get('swagCustomSortName');

        return $name;
    }

    public function hasCustomSort()
    {
        $isLinked = $this->isLinked();
        if ($isLinked) {
            return true;
        }

        $hasOwnSort = $this->hasOwnSort();
        if ($hasOwnSort) {
            return true;
        }

        return false;
    }

    public function isLinked()
    {
        $categoryId = $this->getCategoryId();

        /* @var \Shopware\Models\Attribute\Category $categoryAttributes */
        $categoryAttributes = $this->getCategoryAttributesRepository()->findOneBy(array('categoryId' => $categoryId));
        if (!$categoryAttributes instanceof \Shopware\Models\Attribute\Category) {
            return false;
        }

        $linkedCategoryId = $categoryAttributes->getSwagLink();
        if ($linkedCategoryId === null) {
            return false;
        }

        /* @var \Shopware\Models\Category\Category $category */
        $category = $this->getCategoryRepository()->find($linkedCategoryId);
        if (!$category instanceof \Shopware\Models\Category\Category) {
            return false;
        }

        return true;
    }

    /**
     * Checks whether this category has own custom sort
     *
     * @return bool
     */
    public function hasOwnSort()
    {
        $categoryId = $this->getCategoryId();
        return $this->getCustomSortRepository()->hasCustomSort($categoryId);
    }

    /**
     * Checks whether this category has to use its custom sort by default, e.g. on category load use this custom sort
     *
     * @return bool
     */
    public function showCustomSortAsDefault()
    {
        $categoryId = $this->getCategoryId();

        /* @var \Shopware\Models\Attribute\Category $categoryAttributes */
        $categoryAttributes = $this->getCategoryAttributesRepository()->findOneBy(array('categoryId' => $categoryId));
        if (!$categoryAttributes instanceof \Shopware\Models\Attribute\Category) {
            return false;
        }

        $useDefaultSort = (bool) $categoryAttributes->getSwagShowByDefault();
        $hasOwnSort = $this->hasOwnSort($categoryId);
        if ($useDefaultSort && $hasOwnSort) {
            return true;
        }

        return false;
    }

    /**
     * Returns the id of the linked category.
     *
     * @return int
     */
    public function getLinkedCategoryId()
    {
        $categoryId = $this->getCategoryId();

        /* @var \Shopware\Models\Attribute\Category $categoryAttributes */
        $categoryAttributes = $this->getCategoryAttributesRepository()->findOneBy(array('categoryId' => $categoryId));
        if (!$categoryAttributes instanceof \Shopware\Models\Attribute\Category) {
            return false;
        }

        $linkedCategoryId = $categoryAttributes->getSwagLink();
        if ($linkedCategoryId === null) {
            return false;
        }

        /* @var \Shopware\Models\Category\Category $category */
        $category = $this->getCategoryRepository()->find($linkedCategoryId);
        if (!$category instanceof \Shopware\Models\Category\Category) {
            return false;
        }

        return $linkedCategoryId;
    }
}
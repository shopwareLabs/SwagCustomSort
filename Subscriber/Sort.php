<?php
/**
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Shopware\SwagCustomSort\Subscriber;

use Enlight\Event\SubscriberInterface;
use Enlight_Controller_Request_Request as Request;
use Enlight_Event_EventArgs;
use Shopware\Bundle\SearchBundle\Criteria;
use Shopware\Components\Model\ModelManager;
use Shopware\CustomModels\CustomSort\ProductSort;
use Shopware\SwagCustomSort\Components\Listing;
use Shopware\SwagCustomSort\Components\Sorting;
use Shopware\SwagCustomSort\Sorter\SortDBAL\Handler\DragDropHandler;
use Shopware\SwagCustomSort\Sorter\SortFactory;

class Sort implements SubscriberInterface
{
    /**
     * @var ModelManager
     */
    protected $em;

    /**
     * @var Sorting
     */
    private $sortingComponent;

    /**
     * @var Listing
     */
    private $listingComponent;

    /**
     * @param ModelManager $em
     * @param Sorting      $sortingComponent
     * @param Listing      $listingComponent
     */
    public function __construct(ModelManager $em, Sorting $sortingComponent, Listing $listingComponent)
    {
        $this->em = $em;
        $this->sortingComponent = $sortingComponent;
        $this->listingComponent = $listingComponent;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            'Shopware_SearchBundle_Create_Listing_Criteria' => 'onCreateListingCriteria',
            'Shopware_SearchBundle_Create_Ajax_Listing_Criteria' => 'onCreateListingCriteria',
            'Shopware_SearchBundle_Create_Product_Navigation_Criteria' => 'onCreateListingCriteria',
            'Shopware_SearchBundleDBAL_Collect_Sorting_Handlers' => 'onCollectSortingHandlers',
        ];
    }

    /**
     * When a Criteria is created, check for plugin sorting options. If plugin sorting options exist, add them.
     *
     * @param Enlight_Event_EventArgs $args
     */
    public function onCreateListingCriteria(Enlight_Event_EventArgs $args)
    {
        /** @var Request $request */
        $request = $args->get('request');
        /** @var Criteria $criteria */
        $criteria = $args->get('criteria');

        $allowedActions = ['index', 'ajaxListing', 'productNavigation'];

        //Don't apply custom sort if we are not in category listing
        if (!in_array($request->getActionName(), $allowedActions, true)) {
            return;
        }

        if (!$this->listingComponent instanceof Listing) {
            return;
        }

        $categoryId = (int) $request->getParam('sCategory');
        $useDefaultSort = $this->listingComponent->showCustomSortAsDefault($categoryId);
        $sortName = $this->listingComponent->getFormattedSortName();
        $baseSort = $this->listingComponent->getCategoryBaseSort($categoryId);
        $sortId = $request->getParam('sSort');

        if ((int) $sortId === SortFactory::DRAG_DROP_SORTING) {
            $useDefaultSort = true;
        }

        if ((!$useDefaultSort && $baseSort) || empty($sortName)
            || ($sortId !== null && (int) $sortId !== SortFactory::DRAG_DROP_SORTING)
        ) {
            return;
        }

        $criteria->resetSorting();
        $request->setParam('sSort', SortFactory::DRAG_DROP_SORTING);

        $page = (int) $request->getParam('sPage');
        $offset = (int) $criteria->getOffset();
        $limit = (int) $criteria->getLimit();

        //Get all sorted products for current category and set them in components for further sorting
        $linkedCategoryId = $this->listingComponent->getLinkedCategoryId($categoryId);
        $sortedProducts = $this->em->getRepository(ProductSort::class)
            ->getSortedProducts($categoryId, $linkedCategoryId);
        $this->sortingComponent->setSortedProducts($sortedProducts);

        //Get new offset based on page so we can get correct position of unsorted products
        $newOffset = $this->sortingComponent->getOffset($offset, $page, $limit);

        $this->sortingComponent->setOffsetAndLimit($offset, $limit);

        $criteria->offset($newOffset);

        $sorter = new SortFactory($request, $criteria);
        $sorter->addSort();
    }

    /**
     * Register plugin sorting handlers.
     *
     * @return DragDropHandler
     */
    public function onCollectSortingHandlers()
    {
        return new DragDropHandler($this->sortingComponent, $this->em);
    }
}

<?php
/**
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Shopware\SwagCustomSort\Bundle\StoreFrontBundle;

use Shopware\Bundle\StoreFrontBundle\Service\ListProductServiceInterface;
use Shopware\Bundle\StoreFrontBundle\Struct;
use Shopware\SwagCustomSort\Components\Sorting;

class ListProductService implements ListProductServiceInterface
{
    /**
     * @var ListProductServiceInterface
     */
    private $coreService;

    /**
     * @var Sorting
     */
    private $sortingComponent;

    /**
     * @param ListProductServiceInterface $coreService
     * @param Sorting                     $sortingComponent
     */
    public function __construct(ListProductServiceInterface $coreService, Sorting $sortingComponent)
    {
        $this->coreService = $coreService;
        $this->sortingComponent = $sortingComponent;
    }

    /**
     * {@inheritdoc}
     */
    public function get($number, Struct\ProductContextInterface $context)
    {
        $products = $this->getList([$number], $context);

        return array_shift($products);
    }

    /**
     * {@inheritdoc}
     */
    public function getList(array $numbers, Struct\ProductContextInterface $context)
    {
        $getSortedNumbers = $this->sortingComponent->sortByNumber($numbers);

        return $this->coreService->getList($getSortedNumbers, $context);
    }
}

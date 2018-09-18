<?php
/**
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Shopware\SwagCustomSort\Subscriber;

use Enlight\Event\SubscriberInterface;
use Enlight_View_Default;
use Shopware\SwagCustomSort\Components\Listing;
use Shopware_Plugins_Frontend_SwagCustomSort_Bootstrap as PluginBootstrap;

class Frontend implements SubscriberInterface
{
    /**
     * @var PluginBootstrap
     */
    private $bootstrap;

    /**
     * @var string
     */
    private $bootstrapPath;

    /**
     * @param PluginBootstrap $bootstrap
     */
    public function __construct($bootstrap)
    {
        $this->bootstrapPath = $bootstrap->Path();
        $this->bootstrap = $bootstrap;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            'Enlight_Controller_Action_PostDispatchSecure_Frontend_Listing' => 'onPostDispatchSecureListing',
        ];
    }

    /**
     * @param \Enlight_Controller_ActionEventArgs $args
     */
    public function onPostDispatchSecureListing($args)
    {
        /** @var Listing $categoryComponent */
        $categoryComponent = $this->bootstrap->get('swagcustomsort.listing_component');
        if (!$categoryComponent instanceof Listing) {
            return;
        }
        $view = $args->getSubject()->View();
        $categoryId = $view->getAssign('sCategoryContent')['id'];
        $showCustomSort = $categoryComponent->showCustomSortName($categoryId);
        $baseSort = $categoryComponent->getCategoryBaseSort($categoryId);
        $this->addTemplateDir($view);
        if ($showCustomSort || $baseSort > 0) {
            /** @var Listing $categoryComponent */
            $categoryComponent = $this->bootstrap->get('swagcustomsort.listing_component');
            $showCustomSort = $categoryComponent->showCustomSortName($categoryId);
            $useDefaultSort = $categoryComponent->showCustomSortAsDefault($categoryId);
            $baseSort = $categoryComponent->getCategoryBaseSort($categoryId);
            if ($showCustomSort || ($baseSort > 0 && $useDefaultSort)) {
                $showCustomSortOption = true;
            } else {
                $showCustomSortOption = false;
            }

            $view->assign('showCustomSort', $showCustomSortOption);
            $this->extendsTemplate($view, 'frontend/listing/actions/action-sorting.tpl');
        }
    }

    /**
     * @param Enlight_View_Default $view
     */
    protected function addTemplateDir(Enlight_View_Default $view)
    {
        $version = $this->bootstrap->get('shop')->getTemplate()->getVersion();
        if ($version >= 3) {
            $view->addTemplateDir($this->bootstrapPath . 'Views/responsive/');
        } else {
            $view->addTemplateDir($this->bootstrapPath . 'Views/emotion/');
        }
    }

    /**
     * @param Enlight_View_Default $view
     * @param string                $templatePath
     */
    protected function extendsTemplate(Enlight_View_Default $view, $templatePath)
    {
        $version = $this->bootstrap->get('shop')->getTemplate()->getVersion();
        if ($version < 3) {
            $view->extendsTemplate($templatePath);
        }
    }
}

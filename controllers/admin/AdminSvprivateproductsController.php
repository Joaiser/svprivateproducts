<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Back office entrypoint tab.
 *
 * We keep the module UI in the module configuration (AdminModules) to avoid duplicating tokens/flows.
 * This controller exists only to provide a Catalog tab that redirects to the module configuration page.
 */
class AdminSvprivateproductsController extends ModuleAdminController
{
    public function initProcess()
    {
        parent::initProcess();

        $url = $this->context->link->getAdminLink('AdminModules', true, [], [
            'configure' => 'svprivateproducts',
        ]);

        Tools::redirectAdmin($url);
    }
}

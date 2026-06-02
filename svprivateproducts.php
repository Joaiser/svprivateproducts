<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/PrivateProductAccessRepository.php';

class SvPrivateProducts extends Module
{
    public const CFG_DEBUG = 'SVPRIVATEPRODUCTS_DEBUG';

    public const CFG_DENY_MODE = 'SVPP_DENY_MODE'; // 404|redirect
    public const CFG_REDIRECT_TYPE = 'SVPP_REDIRECT_TYPE'; // product|url
    public const CFG_REDIRECT_PRODUCT = 'SVPP_REDIRECT_PRODUCT';
    public const CFG_REDIRECT_URL = 'SVPP_REDIRECT_URL';

    public function __construct()
    {
        $this->name = 'svprivateproducts';
        $this->tab = 'administration';
        $this->version = '1.0.1';
        $this->author = 'Aitor';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('SV Private Products');
        $this->description = $this->l('Restrict product visibility/purchase to specific customers (direct customer-product relations).');
        $this->ps_versions_compliancy = ['min' => '8.2.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        return parent::install()
            && $this->installDb()
            && Configuration::updateValue(self::CFG_DEBUG, 0)
            && Configuration::updateValue(self::CFG_DENY_MODE, '404')
            && Configuration::updateValue(self::CFG_REDIRECT_TYPE, 'product')
            && Configuration::updateValue(self::CFG_REDIRECT_PRODUCT, 0)
            && Configuration::updateValue(self::CFG_REDIRECT_URL, '')
            && $this->installTab()
            && $this->registerHook('actionDispatcherBefore')
            && $this->registerHook('actionProductSearchProviderRunQueryAfter')
            && $this->registerHook('actionFrontControllerInitBefore')
            && $this->registerHook('actionCartUpdateQuantityBefore')
            && $this->registerHook('displayHeader')
            && $this->registerHook('displayBackOfficeHeader');
    }

    public function uninstall()
    {
        return $this->uninstallDb()
            && Configuration::deleteByName(self::CFG_DEBUG)
            && Configuration::deleteByName(self::CFG_DENY_MODE)
            && Configuration::deleteByName(self::CFG_REDIRECT_TYPE)
            && Configuration::deleteByName(self::CFG_REDIRECT_PRODUCT)
            && Configuration::deleteByName(self::CFG_REDIRECT_URL)
            && $this->uninstallTab()
            && parent::uninstall();
    }

    private function installDb(): bool
    {
        $sqlAccess = 'CREATE TABLE IF NOT EXISTS `' . bqSQL(SvPrivateProductsPrivateProductAccessRepository::tableName()) . '` (
            `id_access` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL,
            `id_customer` INT UNSIGNED NOT NULL,
            `id_product` INT UNSIGNED NOT NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_access`),
            UNIQUE KEY `uniq_shop_customer_product` (`id_shop`, `id_customer`, `id_product`),
            KEY `idx_shop` (`id_shop`),
            KEY `idx_customer` (`id_customer`),
            KEY `idx_product` (`id_product`),
            KEY `idx_active` (`active`),
            KEY `idx_auth_lookup` (`id_shop`, `id_product`, `id_customer`, `active`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        $sqlRedirect = 'CREATE TABLE IF NOT EXISTS `' . bqSQL($this->redirectTableName()) . '` (
            `id_redirect` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL,
            `id_private_product` INT UNSIGNED NOT NULL,
            `id_redirect_product` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_redirect`),
            UNIQUE KEY `uniq_shop_private_product` (`id_shop`, `id_private_product`),
            KEY `idx_redirect_product` (`id_redirect_product`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        return (bool) Db::getInstance()->execute($sqlAccess)
            && (bool) Db::getInstance()->execute($sqlRedirect);
    }

    private function uninstallDb(): bool
    {
        $sqlAccess = 'DROP TABLE IF EXISTS `' . bqSQL(SvPrivateProductsPrivateProductAccessRepository::tableName()) . '`';
        $sqlRedirect = 'DROP TABLE IF EXISTS `' . bqSQL($this->redirectTableName()) . '`';

        return (bool) Db::getInstance()->execute($sqlRedirect)
            && (bool) Db::getInstance()->execute($sqlAccess);
    }

    public function getContent()
    {
        if (!$this->context->employee || !$this->context->employee->id) {
            return '';
        }

        $this->ensureHooks();
        $this->installDb();

        $this->trace('bo_getContent', [
            'controller' => is_object($this->context->controller) ? get_class($this->context->controller) : 'none',
            'url' => (string) Tools::getValue('configure'),
        ]);

        // AJAX endpoints for back office autocompletes.
        if ((int) Tools::getValue('ajax') === 1) {
            $this->handleAdminAjax();
        }

        // Ensure assets are loaded even if the module was installed before we registered displayBackOfficeHeader.
        if ($this->context->controller && is_object($this->context->controller)) {
            $this->context->controller->addJS($this->_path . 'views/js/admin-autocomplete.js');
            $this->context->controller->addCSS($this->_path . 'views/css/admin-autocomplete.css');

            Media::addJsDef([
                'svppAdminAjaxUrl' => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                'svppTrace' => $this->isTraceEnabled() ? 1 : 0,
            ]);

            $this->trace('bo_assets_injected', [
                'js' => $this->_path . 'views/js/admin-autocomplete.js',
                'css' => $this->_path . 'views/css/admin-autocomplete.css',
                'ajaxUrl' => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
            ]);
        }

        $out = '';
        $out .= $this->postProcess();
        $out .= $this->renderUnifiedUi();
        $out .= $this->renderAccessList();

        return $out;
    }

    private function postProcess(): string
    {
        $out = '';
        $token = (string) Tools::getValue('token');
        $expectedToken = (string) Tools::getAdminTokenLite('AdminModules');

        $idShop = (int) $this->context->shop->id;

        if (Tools::isSubmit('submitSvPrivateProductsConfig')) {
            if ($token !== $expectedToken) {
                return $this->displayError($this->l('Invalid token.'));
            }

            $debug = (int) Tools::getValue(self::CFG_DEBUG);
            Configuration::updateValue(self::CFG_DEBUG, $debug ? 1 : 0);

            $denyMode = (string) Tools::getValue(self::CFG_DENY_MODE, '404');
            if (!in_array($denyMode, ['404', 'redirect'], true)) {
                $denyMode = '404';
            }
            Configuration::updateValue(self::CFG_DENY_MODE, $denyMode);

            $redirType = (string) Tools::getValue(self::CFG_REDIRECT_TYPE, 'product');
            if (!in_array($redirType, ['product', 'url'], true)) {
                $redirType = 'product';
            }
            Configuration::updateValue(self::CFG_REDIRECT_TYPE, $redirType);

            $redirProduct = Tools::getValue(self::CFG_REDIRECT_PRODUCT);
            if (!Validate::isUnsignedId($redirProduct)) {
                $redirProduct = 0;
            }
            Configuration::updateValue(self::CFG_REDIRECT_PRODUCT, (int) $redirProduct);

            $redirUrl = (string) Tools::getValue(self::CFG_REDIRECT_URL, '');
            Configuration::updateValue(self::CFG_REDIRECT_URL, trim($redirUrl));

            $this->clearModuleCache();

            return $this->displayConfirmation($this->l('Settings updated.'));
        }

        if (Tools::isSubmit('submitSvppAssignCustomers')) {
            if ($token !== $expectedToken) {
                return $this->displayError($this->l('Invalid token.'));
            }

            $idProduct = Tools::getValue('svpp_assign_id_product');
            $idsCustomers = Tools::getValue('svpp_assign_customers');
            $active = (int) Tools::getValue('svpp_assign_active');
            $replace = (int) Tools::getValue('svpp_assign_replace');
            $idRedirectProduct = Tools::getValue('svpp_assign_redirect_id_product');

            if (!Validate::isUnsignedId($idProduct) || (int) $idProduct <= 0) {
                return $this->displayError($this->l('Please select a valid product.'));
            }
            $idProduct = (int) $idProduct;

            if (!is_array($idsCustomers) || empty($idsCustomers)) {
                return $this->displayError($this->l('Please select at least one customer.'));
            }

            $cleanCustomers = [];
            foreach ($idsCustomers as $idCustomer) {
                if (Validate::isUnsignedId($idCustomer) && (int) $idCustomer > 0) {
                    $cleanCustomers[] = (int) $idCustomer;
                }
            }
            $cleanCustomers = array_values(array_unique($cleanCustomers));
            if (empty($cleanCustomers)) {
                return $this->displayError($this->l('Please select at least one valid customer.'));
            }

            if (!Validate::isUnsignedId($idRedirectProduct)) {
                $idRedirectProduct = 0;
            }
            $idRedirectProduct = (int) $idRedirectProduct;
            if ($idRedirectProduct === $idProduct) {
                return $this->displayError($this->l('Redirect product cannot be the same private product.'));
            }
            if ($idRedirectProduct > 0 && SvPrivateProductsPrivateProductAccessRepository::isProductPrivate($idRedirectProduct, (int) $idShop)) {
                return $this->displayError($this->l('Redirect product must be public.'));
            }

            $table = bqSQL(SvPrivateProductsPrivateProductAccessRepository::tableName());
            $now = pSQL(date('Y-m-d H:i:s'));

            // Default semantics: ADD/UPDATE selected customers without removing existing ones.
            // Optional: replace entire list for this product in this shop.
            if ($replace === 1) {
                Db::getInstance()->execute('DELETE FROM `' . $table . '` WHERE `id_shop` = ' . (int) $idShop . ' AND `id_product` = ' . (int) $idProduct);
            }

            $ok = true;
            foreach ($cleanCustomers as $idCustomer) {
                $sql = 'INSERT INTO `' . $table . '` (`id_shop`,`id_customer`,`id_product`,`active`,`date_add`,`date_upd`)
                    VALUES (' . (int) $idShop . ',' . (int) $idCustomer . ',' . (int) $idProduct . ',' . ($active ? 1 : 0) . ',\'' . $now . '\',\'' . $now . '\')
                    ON DUPLICATE KEY UPDATE `active` = VALUES(`active`), `date_upd` = VALUES(`date_upd`)';
                $ok = $ok && (bool) Db::getInstance()->execute($sql);
            }

            if (!$ok) {
                return $this->displayError($this->l('Could not save relations.'));
            }

            if (!$this->saveProductRedirect($idProduct, $idRedirectProduct, $idShop)) {
                return $this->displayError($this->l('Could not save product redirect.'));
            }

            $this->clearModuleCache();

            return $this->displayConfirmation($this->l('Relations updated for this product.'));
        }

        if (Tools::isSubmit('submitSvppRedirect')) {
            if ($token !== $expectedToken) {
                return $this->displayError($this->l('Invalid token.'));
            }

            $idRedirectProduct = Tools::getValue('svpp_redirect_id_product');
            if (!Validate::isUnsignedId($idRedirectProduct)) {
                $idRedirectProduct = 0;
            }
            $idRedirectProduct = (int) $idRedirectProduct;
            $forcePublic = (int) Tools::getValue('svpp_redirect_force_public');

            if ($idRedirectProduct > 0) {
                // Guardrail: redirect target must be PUBLIC (otherwise it may also be hidden/blocked).
                if (SvPrivateProductsPrivateProductAccessRepository::isProductPrivate($idRedirectProduct, (int) $idShop)) {
                    if ($forcePublic === 1) {
                        $table = bqSQL(SvPrivateProductsPrivateProductAccessRepository::tableName());
                        // Make it public by disabling any active relations for this product in this shop.
                        Db::getInstance()->execute('UPDATE `' . $table . '` SET `active` = 0, `date_upd` = \'' . pSQL(date('Y-m-d H:i:s')) . '\' WHERE `id_shop` = ' . (int) $idShop . ' AND `id_product` = ' . (int) $idRedirectProduct);
                    } else {
                    Configuration::updateValue(self::CFG_DENY_MODE, '404');
                    Configuration::updateValue(self::CFG_REDIRECT_PRODUCT, 0);
                    $this->clearModuleCache();

                    return $this->displayError($this->l('Redirect product must be public. If you want to use it, enable "Force public" to deactivate its private relations.'));
                    }
                }

                Configuration::updateValue(self::CFG_DENY_MODE, 'redirect');
                Configuration::updateValue(self::CFG_REDIRECT_TYPE, 'product');
                Configuration::updateValue(self::CFG_REDIRECT_PRODUCT, $idRedirectProduct);
            } else {
                // Empty redirect = strict 404.
                Configuration::updateValue(self::CFG_DENY_MODE, '404');
                Configuration::updateValue(self::CFG_REDIRECT_PRODUCT, 0);
            }

            $this->clearModuleCache();

            return $this->displayConfirmation($this->l('Redirect settings updated.'));
        }

        if (Tools::isSubmit('submitSvPrivateProductsAddAccess')) {
            if ($token !== $expectedToken) {
                return $this->displayError($this->l('Invalid token.'));
            }

            $idCustomer = Tools::getValue('id_customer');
            $idProduct = Tools::getValue('id_product');
            $active = (int) Tools::getValue('active');

            $errors = [];
            if (!Validate::isUnsignedId($idCustomer) || (int) $idCustomer <= 0) {
                $errors[] = $this->l('Invalid id_customer');
            }
            if (!Validate::isUnsignedId($idProduct) || (int) $idProduct <= 0) {
                $errors[] = $this->l('Invalid id_product');
            }

            if (!empty($errors)) {
                return $this->displayError(implode(' ', $errors));
            }

            $now = date('Y-m-d H:i:s');
            $data = [
                'id_shop' => $idShop,
                'id_customer' => (int) $idCustomer,
                'id_product' => (int) $idProduct,
                'active' => $active ? 1 : 0,
                'date_add' => pSQL($now),
                'date_upd' => pSQL($now),
            ];

            // Upsert-like: try insert, if duplicate update.
            $table = bqSQL(SvPrivateProductsPrivateProductAccessRepository::tableName());
            $sql = 'INSERT INTO `' . $table . '` (`id_shop`,`id_customer`,`id_product`,`active`,`date_add`,`date_upd`)
                VALUES (' . (int) $data['id_shop'] . ',' . (int) $data['id_customer'] . ',' . (int) $data['id_product'] . ',' . (int) $data['active'] . ',\'' . $data['date_add'] . '\',\'' . $data['date_upd'] . '\')
                ON DUPLICATE KEY UPDATE `active` = VALUES(`active`), `date_upd` = VALUES(`date_upd`)';

            if (!Db::getInstance()->execute($sql)) {
                return $this->displayError($this->l('Could not save the relation.'));
            }

            $this->clearModuleCache();

            return $this->displayConfirmation($this->l('Relation saved.'));
        }

        // List actions (activate/deactivate/delete) - prefer POST.
        if (Tools::isSubmit('svpp_action')) {
            if ($token !== $expectedToken) {
                return $this->displayError($this->l('Invalid token.'));
            }

            $action = (string) Tools::getValue('svpp_action');
            $idAccess = Tools::getValue('id_access');
            if (!Validate::isUnsignedId($idAccess) || (int) $idAccess <= 0) {
                return $this->displayError($this->l('Invalid id_access'));
            }
            $idAccess = (int) $idAccess;

            $table = bqSQL(SvPrivateProductsPrivateProductAccessRepository::tableName());

            if ($action === 'toggle') {
                $row = Db::getInstance()->getRow('SELECT `active` FROM `' . $table . '` WHERE `id_access` = ' . $idAccess . ' AND `id_shop` = ' . $idShop);
                if (!$row) {
                    return $this->displayError($this->l('Relation not found.'));
                }
                $newActive = ((int) $row['active']) ? 0 : 1;
                $sql = 'UPDATE `' . $table . '`
                    SET `active` = ' . (int) $newActive . ', `date_upd` = \'' . pSQL(date('Y-m-d H:i:s')) . '\'
                    WHERE `id_access` = ' . $idAccess . ' AND `id_shop` = ' . $idShop;
                if (!Db::getInstance()->execute($sql)) {
                    return $this->displayError($this->l('Could not update relation.'));
                }
                $this->clearModuleCache();

                return $this->displayConfirmation($this->l('Relation updated.'));
            }

            if ($action === 'delete') {
                $sql = 'DELETE FROM `' . $table . '` WHERE `id_access` = ' . $idAccess . ' AND `id_shop` = ' . $idShop;
                if (!Db::getInstance()->execute($sql)) {
                    return $this->displayError($this->l('Could not delete relation.'));
                }
                $this->clearModuleCache();

                return $this->displayConfirmation($this->l('Relation deleted.'));
            }

            $out .= $this->displayError($this->l('Unknown action.'));
        }

        return $out;
    }

    private function renderUnifiedUi(): string
    {
        $token = Tools::getAdminTokenLite('AdminModules');
        $action = AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . urlencode($token);

        $html = '';

        // Hard-load assets inline to avoid BO asset pipeline edge cases.
        // If these tags appear in HTML but the files 404, then the module folder/path is not the one deployed.
        $assetBust = (string) $this->version;
        $html .= '<link rel="stylesheet" type="text/css" href="' . htmlspecialchars($this->_path . 'views/css/admin-autocomplete.css?v=' . rawurlencode($assetBust), ENT_QUOTES, 'UTF-8') . '" />';
        $html .= '<script type="text/javascript">'
            . 'window.svppAdminAjaxUrl=' . json_encode($action) . ';'
            . 'window.svppTrace=' . json_encode($this->isTraceEnabled() ? 1 : 0) . ';'
            . 'console.debug("[svprivateproducts] inline boot",{ajaxUrl:window.svppAdminAjaxUrl,trace:window.svppTrace});'
            . '</script>';
        $html .= '<script type="text/javascript" src="' . htmlspecialchars($this->_path . 'views/js/admin-autocomplete.js?v=' . rawurlencode($assetBust), ENT_QUOTES, 'UTF-8') . '"></script>';

        $html .= '<div class="panel"><h3>' . htmlspecialchars($this->l('Private products: assign customers'), ENT_QUOTES, 'UTF-8') . '</h3>';
        $html .= '<div class="row">';

        // Single form covering product + customers columns.
        $html .= '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '" class="svpp-form">';

        // Column 1: product
        $html .= '<div class="col-lg-4">';
        $html .= '<div class="form-group">';
        $html .= '<label>' . htmlspecialchars($this->l('Product'), ENT_QUOTES, 'UTF-8') . '</label>';
        $html .= '<input type="text" name="svpp_ui_product_search" class="form-control" autocomplete="off" placeholder="' . htmlspecialchars($this->l('Type name / reference...'), ENT_QUOTES, 'UTF-8') . '" />';
        $html .= '<input type="hidden" name="svpp_assign_id_product" value="" />';
        $html .= '</div>';
        $html .= '<div class="form-group">';
        $html .= '<label>' . htmlspecialchars($this->l('Active'), ENT_QUOTES, 'UTF-8') . '</label>';
        $html .= '<select name="svpp_assign_active" class="form-control"><option value="1">' . htmlspecialchars($this->l('Yes'), ENT_QUOTES, 'UTF-8') . '</option><option value="0">' . htmlspecialchars($this->l('No'), ENT_QUOTES, 'UTF-8') . '</option></select>';
        $html .= '<p class="help-block">' . htmlspecialchars($this->l('This will add/update selected customers for the product. Enable replace to overwrite the whole list.'), ENT_QUOTES, 'UTF-8') . '</p>';
        $html .= '</div>';

        $html .= '<div class="form-group">';
        $html .= '<label><input type="checkbox" name="svpp_assign_replace" value="1" /> ' . htmlspecialchars($this->l('Replace existing list'), ENT_QUOTES, 'UTF-8') . '</label>';
        $html .= '</div>';
        $html .= '</div>'; // col

        // Column 2: customers multi
        $html .= '<div class="col-lg-4">';
        $html .= '<div class="form-group">';
        $html .= '<label>' . htmlspecialchars($this->l('Customers allowed to see this product'), ENT_QUOTES, 'UTF-8') . '</label>';
        $html .= '<input type="text" name="svpp_ui_customer_search" class="form-control" autocomplete="off" placeholder="' . htmlspecialchars($this->l('Type email / name...'), ENT_QUOTES, 'UTF-8') . '" />';
        $html .= '<div class="svpp-chips" data-target="svpp_assign_customers"></div>';
        $html .= '<div class="svpp-hidden" data-name="svpp_assign_customers"></div>';
        $html .= '</div>';
        $html .= '<button type="submit" name="submitSvppAssignCustomers" class="btn btn-primary">' . htmlspecialchars($this->l('Save'), ENT_QUOTES, 'UTF-8') . '</button>';
        $html .= '</div>'; // col

        // Column 3: per-private-product redirect.
        $html .= '<div class="col-lg-4">';
        $html .= '<div class="form-group">';
        $html .= '<label>' . htmlspecialchars($this->l('Redirect unauthorized to product'), ENT_QUOTES, 'UTF-8') . '</label>';
        $html .= '<input type="text" name="svpp_ui_assign_redirect_product_search" class="form-control" autocomplete="off" placeholder="' . htmlspecialchars($this->l('Optional. Leave empty for 404.'), ENT_QUOTES, 'UTF-8') . '" />';
        $html .= '<input type="hidden" name="svpp_assign_redirect_id_product" value="0" />';
        $html .= '<p class="help-block">' . htmlspecialchars($this->l('Saved for the selected private product. Unauthorized customers redirect here; allowed customers still see the private product.'), ENT_QUOTES, 'UTF-8') . '</p>';
        $html .= '</div>';
        $html .= '</div>'; // col

        $html .= '</form>';

        $html .= '</div></div>'; // row/panel

        // Keep debug toggle (simple) below.
        $html .= $this->renderDebugToggle();

        return $html;
    }

    private function renderDebugToggle(): string
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitSvPrivateProductsConfig';
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $fieldsForm = [
            'form' => [
                'legend' => ['title' => $this->l('Debug')],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Debug logs'),
                        'name' => self::CFG_DEBUG,
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'debug_on', 'value' => 1, 'label' => $this->l('Enabled')],
                            ['id' => 'debug_off', 'value' => 0, 'label' => $this->l('Disabled')],
                        ],
                    ],
                ],
                'submit' => ['title' => $this->l('Save')],
            ],
        ];

        $helper->fields_value = [
            self::CFG_DEBUG => (int) Configuration::get(self::CFG_DEBUG),
        ];

        return $helper->generateForm([$fieldsForm]);
    }

    private function renderAccessList(): string
    {
        $idShop = (int) $this->context->shop->id;
        $table = bqSQL(SvPrivateProductsPrivateProductAccessRepository::tableName());
        $redirectTable = bqSQL($this->redirectTableName());
        $idLang = (int) $this->context->language->id;
        $rows = Db::getInstance()->executeS('SELECT a.`id_access`, a.`id_shop`, a.`id_customer`, a.`id_product`, a.`active`, a.`date_add`, a.`date_upd`,
                CONCAT(c.`firstname`, " ", c.`lastname`, " (", c.`email`, ")") AS customer_name,
                CONCAT(pl.`name`, IF(p.`reference` IS NULL OR p.`reference` = "", "", CONCAT(" [", p.`reference`, "]"))) AS product_name,
                r.`id_redirect_product`,
                CONCAT(rpl.`name`, IF(rp.`reference` IS NULL OR rp.`reference` = "", "", CONCAT(" [", rp.`reference`, "]"))) AS redirect_product_name
            FROM `' . $table . '` a
            LEFT JOIN `' . bqSQL(_DB_PREFIX_ . 'customer') . '` c ON (c.`id_customer` = a.`id_customer`)
            LEFT JOIN `' . bqSQL(_DB_PREFIX_ . 'product') . '` p ON (p.`id_product` = a.`id_product`)
            LEFT JOIN `' . bqSQL(_DB_PREFIX_ . 'product_lang') . '` pl
                ON (pl.`id_product` = a.`id_product` AND pl.`id_lang` = ' . $idLang . ' AND pl.`id_shop` = ' . $idShop . ')
            LEFT JOIN `' . $redirectTable . '` r
                ON (r.`id_shop` = a.`id_shop` AND r.`id_private_product` = a.`id_product`)
            LEFT JOIN `' . bqSQL(_DB_PREFIX_ . 'product') . '` rp ON (rp.`id_product` = r.`id_redirect_product`)
            LEFT JOIN `' . bqSQL(_DB_PREFIX_ . 'product_lang') . '` rpl
                ON (rpl.`id_product` = r.`id_redirect_product` AND rpl.`id_lang` = ' . $idLang . ' AND rpl.`id_shop` = ' . $idShop . ')
            WHERE a.`id_shop` = ' . $idShop . '
            ORDER BY a.`date_upd` DESC');

        // Custom HTML table to keep Actions simple and avoid HelperList escaping quirks.
        $html = '<div class="panel">';
        $html .= '<h3>' . htmlspecialchars($this->l('Relations (current shop)'), ENT_QUOTES, 'UTF-8') . '</h3>';
        // (Moved into the table as a single summary row)

        if (empty($rows)) {
            $html .= '<p class="alert alert-info">' . htmlspecialchars($this->l('No records found.'), ENT_QUOTES, 'UTF-8') . '</p>';
            $html .= '</div>';
            return $html;
        }

        $html .= '<div class="table-responsive">';
        $html .= '<table class="table">';
        $html .= '<thead><tr>';
        $html .= '<th>' . htmlspecialchars($this->l('ID'), ENT_QUOTES, 'UTF-8') . '</th>';
        $html .= '<th>' . htmlspecialchars($this->l('Customer'), ENT_QUOTES, 'UTF-8') . '</th>';
        $html .= '<th>' . htmlspecialchars($this->l('Product'), ENT_QUOTES, 'UTF-8') . '</th>';
        $html .= '<th>' . htmlspecialchars($this->l('Redirect'), ENT_QUOTES, 'UTF-8') . '</th>';
        $html .= '<th>' . htmlspecialchars($this->l('Active'), ENT_QUOTES, 'UTF-8') . '</th>';
        $html .= '<th>' . htmlspecialchars($this->l('Updated'), ENT_QUOTES, 'UTF-8') . '</th>';
        $html .= '<th>' . htmlspecialchars($this->l('Actions'), ENT_QUOTES, 'UTF-8') . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $r) {
            $idAccess = (int) $r['id_access'];
            $active = (int) $r['active'];
            $redirectProductId = (int) $r['id_redirect_product'];
            $redirectLabel = $redirectProductId > 0 && !empty($r['redirect_product_name'])
                ? '#' . $redirectProductId . ' ' . (string) $r['redirect_product_name']
                : $this->l('404');
            $badge = $active ? '<span class="label label-success">' . htmlspecialchars($this->l('Enabled'), ENT_QUOTES, 'UTF-8') . '</span>'
                : '<span class="label label-default">' . htmlspecialchars($this->l('Disabled'), ENT_QUOTES, 'UTF-8') . '</span>';

            $html .= '<tr>';
            $html .= '<td>' . (int) $idAccess . '</td>';
            $html .= '<td>' . htmlspecialchars((string) $r['customer_name'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars((string) $r['product_name'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($redirectLabel, ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . $badge . '</td>';
            $html .= '<td>' . htmlspecialchars((string) $r['date_upd'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . $this->renderRowActions($idAccess, $active) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div></div>';

        return $html;
    }

    private function renderRowActions(int $idAccess, int $active): string
    {
        $token = Tools::getAdminTokenLite('AdminModules');
        $base = AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . urlencode($token);

        // Compact UI: two clear actions, POST-only.
        $toggleLabel = $active ? $this->l('Disable') : $this->l('Enable');
        $toggleBtnClass = $active ? 'btn btn-default btn-sm' : 'btn btn-primary btn-sm';

        $toggle = '<form method="post" action="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;">
            <input type="hidden" name="svpp_action" value="toggle" />
            <input type="hidden" name="id_access" value="' . (int) $idAccess . '" />
            <button type="submit" class="' . $toggleBtnClass . '">' . htmlspecialchars($toggleLabel, ENT_QUOTES, 'UTF-8') . '</button>
        </form>';

        $del = '<form method="post" action="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;">
            <input type="hidden" name="svpp_action" value="delete" />
            <input type="hidden" name="id_access" value="' . (int) $idAccess . '" />
            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm(\'' . addslashes($this->l('Delete this relation?')) . '\');">' . htmlspecialchars($this->l('Delete'), ENT_QUOTES, 'UTF-8') . '</button>
        </form>';

        return '<div class="btn-group" role="group" aria-label="svprivateproducts-actions">' . $toggle . $del . '</div>';
    }

    /**
     * MAIN GOAL: hide private products in standard listings for unauthorized customers.
     *
     * This hook is part of the ProductSearch flow (category/search/manufacturer/new-products/best-sales/etc.)
     * in PrestaShop 8 when using standard controllers/providers.
     *
     * NOTE: Some themes/modules may bypass ProductSearch; those cases are out of scope without overrides.
     */
    public function hookActionProductSearchProviderRunQueryAfter(array $params)
    {
        if (!isset($params['result'])) {
            return;
        }

        $result = $params['result'];
        if (!is_object($result) || !method_exists($result, 'getProducts') || !method_exists($result, 'setProducts')) {
            return;
        }

        $products = $result->getProducts();
        if (!is_array($products) || empty($products)) {
            return;
        }

        $idShop = (int) $this->context->shop->id;
        $idCustomer = $this->getContextCustomerId();
        $productIds = $this->extractProductIds($products);

        $this->trace('product_search_before_filter', [
            'controller' => is_object($this->context->controller) ? get_class($this->context->controller) : 'none',
            'php_self' => is_object($this->context->controller) && property_exists($this->context->controller, 'php_self') ? (string) $this->context->controller->php_self : '',
            'customer_logged' => ($this->context->customer && $this->context->customer->isLogged()) ? 1 : 0,
            'customer' => $idCustomer,
            'products_count' => count($products),
            'products' => implode(',', $productIds),
        ]);

        $filtered = SvPrivateProductsPrivateProductAccessRepository::filterProductsForCustomer($products, $idCustomer, $idShop);

        $this->trace('product_search_after_filter', [
            'customer' => $idCustomer,
            'products_count_before' => count($products),
            'products_count_after' => count($filtered),
            'products_after' => implode(',', $this->extractProductIds($filtered)),
        ]);

        if (count($filtered) !== count($products)) {
            $result->setProducts($filtered);

            // Adjust total if API is available.
            if (method_exists($result, 'getTotalProductsCount') && method_exists($result, 'setTotalProductsCount')) {
                $result->setTotalProductsCount(count($filtered));
            }
        }
    }

    public function hookActionDispatcherBefore(array $params)
    {
        if (defined('_PS_ADMIN_DIR_') && strpos((string) ($_SERVER['REQUEST_URI'] ?? ''), basename(_PS_ADMIN_DIR_)) !== false) {
            return;
        }

        $idProduct = $this->getProductIdFromRequestUri();
        if ($idProduct <= 0) {
            return;
        }

        $this->checkProductAccess($idProduct, 'dispatcher_access_check');
    }

    /**
     * Front controller fallback protection for product URLs not caught by the dispatcher hook.
     */
    public function hookActionFrontControllerInitBefore(array $params)
    {
        $controller = $this->context->controller;
        if (!is_object($controller)) {
            return;
        }

        $phpSelf = property_exists($controller, 'php_self') ? (string) $controller->php_self : '';
        $idRequestProduct = $this->getRequestProductId($controller);
        $this->trace('front_controller_access_probe', [
            'controller' => get_class($controller),
            'php_self' => $phpSelf,
            'customer_logged' => ($this->context->customer && $this->context->customer->isLogged()) ? 1 : 0,
            'customer' => $this->getContextCustomerId(),
            'request_product' => $idRequestProduct,
            'request_controller' => (string) Tools::getValue('controller'),
            'request_uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
        ]);

        // Product page protection.
        if ($phpSelf === 'product' || $idRequestProduct > 0) {
            $this->checkProductAccess($idRequestProduct, 'product_view_access_check');
        }

        // Minimal add-to-cart safeguard for direct calls.
        // Covers common patterns: controller=cart&add=1&id_product=XX and ajax cart requests.
        if ($phpSelf === 'cart') {
            $add = (int) Tools::getValue('add');
            $op = (string) Tools::getValue('op');
            $idProduct = (int) Tools::getValue('id_product');
            $idShop = (int) $this->context->shop->id;
            $idCustomer = $this->getContextCustomerId();
            $isAdd = ($add === 1) || ($op === 'up');

            if ($isAdd && $idProduct > 0 && !SvPrivateProductsPrivateProductAccessRepository::canCustomerAccessProduct($idCustomer, $idProduct, $idShop)) {
                $this->logBlocked('cart_add', $idCustomer, $idProduct);

                // For cart operations (often AJAX), prefer 404 to avoid leaking details.
                $this->denyAccess(true);
            }
        }
    }

    /**
     * Extra safeguard: blocks quantity updates for unauthorized customers.
     *
     * This is called before updating cart quantity when core uses Cart::updateQty.
     */
    public function hookActionCartUpdateQuantityBefore(array $params)
    {
        if (empty($params['id_product'])) {
            return;
        }

        $idProduct = (int) $params['id_product'];
        $idShop = (int) $this->context->shop->id;
        $idCustomer = $this->getContextCustomerId();

        if ($idProduct > 0 && !SvPrivateProductsPrivateProductAccessRepository::canCustomerAccessProduct($idCustomer, $idProduct, $idShop)) {
            $this->logBlocked('cart_update_qty', $idCustomer, $idProduct);

            // Keep behavior minimal and consistent with "do not reveal".
            $this->denyAccess(true);
        }
    }

    public function hookDisplayBackOfficeHeader()
    {
        // Only load assets on this module configuration page.
        if ((string) Tools::getValue('configure') !== $this->name) {
            return;
        }

        $this->trace('bo_header_hook', [
            'controller' => is_object($this->context->controller) ? get_class($this->context->controller) : 'none',
        ]);

        $this->context->controller->addJS($this->_path . 'views/js/admin-autocomplete.js');
        $this->context->controller->addCSS($this->_path . 'views/css/admin-autocomplete.css');

        Media::addJsDef([
            'svppAdminAjaxUrl' => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
            'svppTrace' => $this->isTraceEnabled() ? 1 : 0,
        ]);
    }

    public function hookDisplayHeader()
    {
        $controller = $this->context->controller;
        $phpSelf = is_object($controller) && property_exists($controller, 'php_self') ? (string) $controller->php_self : '';
        $idProduct = $this->getRequestProductId($controller);

        if ($idProduct <= 0 && $phpSelf === 'product') {
            $idProduct = $this->getProductIdFromRequestUri();
        }

        if ($idProduct <= 0) {
            return;
        }

        $this->trace('display_header_access_probe', [
            'controller' => is_object($controller) ? get_class($controller) : 'none',
            'php_self' => $phpSelf,
            'product' => $idProduct,
            'request_uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
        ]);

        $this->checkProductAccess($idProduct, 'display_header_access_check');
    }

    private function denyAccess(bool $prefer404 = false, int $blockedProductId = 0): void
    {
        // If it's an AJAX-ish request or cart context, do not redirect.
        $isAjax = (int) Tools::getValue('ajax') === 1
            || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

        if (!$prefer404 && !$isAjax) {
            $url = $this->getRedirectUrl($blockedProductId);
            if ($url) {
                header('Cache-Control: no-store, no-cache, must-revalidate', true);
                header('Location: ' . $url, true, 302);
                exit;
            }
        }

        if (function_exists('http_response_code')) {
            http_response_code(404);
        } else {
            header((isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1') . ' 404 Not Found', true, 404);
        }

        header('Cache-Control: no-store, no-cache, must-revalidate', true);
        header('Content-Type: text/html; charset=utf-8', true);
        exit('404 Not Found');
    }

    private function getRedirectUrl(int $blockedProductId = 0): string
    {
        if ($blockedProductId <= 0) {
            return '';
        }

        $idProduct = $this->getRedirectProductForPrivateProduct($blockedProductId, (int) $this->context->shop->id);
        if ($idProduct <= 0 || $idProduct === $blockedProductId) {
            return '';
        }

        return (string) $this->context->link->getProductLink($idProduct);
    }

    private function handleAdminAjax(): void
    {
        $this->trace('bo_ajax_enter', [
            'action' => (string) Tools::getValue('action'),
            'q_len' => Tools::strlen((string) Tools::getValue('q')),
        ]);

        $token = (string) Tools::getValue('token');
        $expectedToken = (string) Tools::getAdminTokenLite('AdminModules');
        if ($token !== $expectedToken || !$this->context->employee || !$this->context->employee->id) {
            $this->trace('bo_ajax_forbidden', [
                'token_ok' => $token === $expectedToken ? 1 : 0,
                'employee' => ($this->context->employee && $this->context->employee->id) ? (int) $this->context->employee->id : 0,
            ]);
            $this->ajaxJson(['error' => 'forbidden'], 403);
        }

        $action = (string) Tools::getValue('action');
        if ($action === 'svppPing') {
            $this->ajaxJson(['ok' => 1, 'ts' => time()]);
        }
        if ($action === 'svppSearchCustomer') {
            $this->ajaxSearchCustomer();
        }
        if ($action === 'svppSearchProduct') {
            $this->ajaxSearchProduct();
        }

        $this->ajaxJson(['error' => 'unknown_action'], 400);
    }

    private function ajaxSearchCustomer(): void
    {
        $q = trim((string) Tools::getValue('q'));
        if ($q === '' || Tools::strlen($q) < 3) {
            $this->ajaxJson(['items' => []]);
        }

        $this->trace('bo_ajax_search_customer', ['q' => $q]);

        $idShop = (int) $this->context->shop->id;
        $limit = 15;
        $where = '';

        if (ctype_digit($q)) {
            $where = 'c.`id_customer` = ' . (int) $q;
        } elseif (strpos($q, '@') !== false) {
            $where = 'c.`email` LIKE \'' . pSQL($q, true) . '%\'';
        } else {
            $prefixLike = pSQL($q, true) . '%';
            $where = '(c.`firstname` LIKE \'' . $prefixLike . '\'
                OR c.`lastname` LIKE \'' . $prefixLike . '\'
                OR c.`email` LIKE \'' . $prefixLike . '\')';

            if (Tools::strlen($q) >= 5) {
                $containsLike = '%' . pSQL($q, true) . '%';
                $where = '(' . $where . '
                    OR c.`firstname` LIKE \'' . $containsLike . '\'
                    OR c.`lastname` LIKE \'' . $containsLike . '\'
                    OR c.`email` LIKE \'' . $containsLike . '\')';
            }
        }

        // Filtering by shop is not essential for the autocomplete and must not break the BO.
        // Some installations have multistore flags enabled but missing `customer_shop` table.
        $shopJoin = '';
        if ($this->tableExists(_DB_PREFIX_ . 'customer_shop')) {
            $shopJoin = ' INNER JOIN `' . bqSQL(_DB_PREFIX_ . 'customer_shop') . '` cs
                ON (cs.`id_customer` = c.`id_customer` AND cs.`id_shop` = ' . $idShop . ')';
        }

        $sql = 'SELECT c.`id_customer`, c.`firstname`, c.`lastname`, c.`email`
            FROM `' . bqSQL(_DB_PREFIX_ . 'customer') . '` c' . $shopJoin . '
            WHERE ' . $where . '
            ORDER BY c.`id_customer` DESC
            LIMIT ' . (int) $limit;

        try {
            $rows = Db::getInstance()->executeS($sql);
        } catch (Exception $e) {
            $this->trace('bo_ajax_search_customer_error', [
                'error' => $e->getMessage(),
                'sql' => $sql,
            ]);
            $this->ajaxJson(['items' => [], 'error' => 'exception'], 500);
        }
        $items = [];
        foreach ($rows ?: [] as $r) {
            $items[] = [
                'id' => (int) $r['id_customer'],
                'label' => (int) $r['id_customer'] . ' - ' . $r['firstname'] . ' ' . $r['lastname'] . ' (' . $r['email'] . ')',
            ];
        }

        $this->trace('bo_ajax_search_customer_done', ['count' => count($items)]);
        $this->ajaxJson(['items' => $items]);
    }

    private function tableExists(string $tableName): bool
    {
        $sql = "SHOW TABLES LIKE '" . pSQL($tableName, true) . "'";
        try {
            return (bool) Db::getInstance()->getValue($sql);
        } catch (Exception $e) {
            return false;
        }
    }

    private function ajaxSearchProduct(): void
    {
        $q = trim((string) Tools::getValue('q'));
        if ($q === '' || Tools::strlen($q) < 2) {
            $this->ajaxJson(['items' => []]);
        }

        $this->trace('bo_ajax_search_product', [
            'q' => $q,
            'source' => (string) Tools::getValue('source'),
        ]);

        $idShop = (int) $this->context->shop->id;
        $idLang = (int) $this->context->language->id;
        $limit = 20;
        $like = '%' . pSQL($q, true) . '%';

        $sql = 'SELECT p.`id_product`, p.`reference`, pl.`name`
            FROM `' . bqSQL(_DB_PREFIX_ . 'product') . '` p
            INNER JOIN `' . bqSQL(_DB_PREFIX_ . 'product_shop') . '` ps
                ON (ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop . ')
            LEFT JOIN `' . bqSQL(_DB_PREFIX_ . 'product_lang') . '` pl
                ON (pl.`id_product` = p.`id_product` AND pl.`id_lang` = ' . $idLang . ' AND pl.`id_shop` = ' . $idShop . ')
            WHERE (pl.`name` LIKE \'' . $like . '\'
                OR p.`reference` LIKE \'' . $like . '\'
                OR p.`id_product` = ' . (int) $q . ')
            ORDER BY p.`id_product` DESC
            LIMIT ' . (int) $limit;

        $rows = Db::getInstance()->executeS($sql);
        $items = [];
        foreach ($rows ?: [] as $r) {
            $label = (int) $r['id_product'] . ' - ' . (string) $r['name'];
            if (!empty($r['reference'])) {
                $label .= ' [' . $r['reference'] . ']';
            }
            $items[] = [
                'id' => (int) $r['id_product'],
                'label' => $label,
            ];
        }

        $this->trace('bo_ajax_search_product_done', [
            'count' => count($items),
            'q' => $q,
            'source' => (string) Tools::getValue('source'),
        ]);
        $this->ajaxJson(['items' => $items]);
    }

    private function isTraceEnabled(): bool
    {
        // Enable via module setting OR temporary URL flag for troubleshooting.
        if ((int) Configuration::get(self::CFG_DEBUG) === 1) {
            return true;
        }
        return (int) Tools::getValue('svpp_trace') === 1;
    }

    private function trace(string $event, array $ctx = []): void
    {
        if (!$this->isTraceEnabled()) {
            return;
        }

        $idShop = (int) ($this->context->shop ? $this->context->shop->id : 0);
        $idEmployee = (int) ($this->context->employee ? $this->context->employee->id : 0);
        $ctx['shop'] = $idShop;
        $ctx['employee'] = $idEmployee;
        $ctx['ip'] = (string) Tools::getRemoteAddr();

        $msg = '[svprivateproducts] ' . $event . ' ' . json_encode($ctx);
        PrestaShopLogger::addLog($msg, 1, null, 'Module', null, true);
    }

    private function ajaxJson(array $payload, int $code = 200): void
    {
        if (function_exists('http_response_code')) {
            http_response_code($code);
        }
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode($payload));
    }

    private function ensureHooks(): void
    {
        foreach (['actionDispatcherBefore', 'actionProductSearchProviderRunQueryAfter', 'actionFrontControllerInitBefore', 'actionCartUpdateQuantityBefore', 'displayHeader', 'displayBackOfficeHeader'] as $hookName) {
            if (!$this->isRegisteredInHook($hookName)) {
                $this->registerHook($hookName);
            }
        }
    }

    private function redirectTableName(): string
    {
        return _DB_PREFIX_ . 'sv_private_product_redirect';
    }

    private function saveProductRedirect(int $idPrivateProduct, int $idRedirectProduct, int $idShop): bool
    {
        $table = bqSQL($this->redirectTableName());
        if ($idRedirectProduct <= 0) {
            return (bool) Db::getInstance()->execute('DELETE FROM `' . $table . '`
                WHERE `id_shop` = ' . (int) $idShop . '
                  AND `id_private_product` = ' . (int) $idPrivateProduct);
        }

        $now = pSQL(date('Y-m-d H:i:s'));
        $sql = 'INSERT INTO `' . $table . '` (`id_shop`, `id_private_product`, `id_redirect_product`, `date_add`, `date_upd`)
            VALUES (' . (int) $idShop . ', ' . (int) $idPrivateProduct . ', ' . (int) $idRedirectProduct . ', \'' . $now . '\', \'' . $now . '\')
            ON DUPLICATE KEY UPDATE `id_redirect_product` = VALUES(`id_redirect_product`), `date_upd` = VALUES(`date_upd`)';

        return (bool) Db::getInstance()->execute($sql);
    }

    private function getRedirectProductForPrivateProduct(int $idPrivateProduct, int $idShop): int
    {
        if ($idPrivateProduct <= 0) {
            return 0;
        }

        $sql = 'SELECT `id_redirect_product`
            FROM `' . bqSQL($this->redirectTableName()) . '`
            WHERE `id_shop` = ' . (int) $idShop . '
              AND `id_private_product` = ' . (int) $idPrivateProduct;

        return (int) Db::getInstance()->getValue($sql);
    }

    private function checkProductAccess(int $idProduct, string $traceEvent): void
    {
        if ($idProduct <= 0) {
            return;
        }

        $idShop = (int) $this->context->shop->id;
        $idCustomer = $this->getContextCustomerId();

        $this->trace('access_check_enter', [
            'event' => $traceEvent,
            'customer_logged' => ($this->context->customer && $this->context->customer->isLogged()) ? 1 : 0,
            'customer' => $idCustomer,
            'product' => $idProduct,
            'request_uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
        ]);

        try {
            $row = Db::getInstance()->getRow('SELECT COUNT(*) AS private_count,
                    SUM(CASE WHEN `id_customer` = ' . (int) $idCustomer . ' THEN 1 ELSE 0 END) AS allowed_count
                FROM `' . bqSQL(SvPrivateProductsPrivateProductAccessRepository::tableName()) . '`
                WHERE `id_shop` = ' . (int) $idShop . '
                  AND `id_product` = ' . (int) $idProduct . '
                  AND `active` = 1');
            $isPrivate = !empty($row) && (int) $row['private_count'] > 0;
            $isAllowed = !empty($row) && (int) $row['allowed_count'] > 0;
        } catch (Exception $e) {
            $this->trace('access_check_error', [
                'event' => $traceEvent,
                'customer' => $idCustomer,
                'product' => $idProduct,
                'error' => $e->getMessage(),
            ]);
            $this->denyAccess(false, $idProduct);
            return;
        }

        $this->trace($traceEvent, [
            'customer_logged' => ($this->context->customer && $this->context->customer->isLogged()) ? 1 : 0,
            'customer' => $idCustomer,
            'product' => $idProduct,
            'is_private' => $isPrivate ? 1 : 0,
            'is_allowed' => $isAllowed ? 1 : 0,
            'request_uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
        ]);

        if ($isPrivate && !$isAllowed) {
            $this->logBlocked('product_view', $idCustomer, $idProduct);
            $this->denyAccess(false, $idProduct);
        }
    }

    private function getContextCustomerId(): int
    {
        if ($this->context->customer && $this->context->customer->isLogged()) {
            return (int) $this->context->customer->id;
        }

        if ($this->context->cookie && !empty($this->context->cookie->id_customer)) {
            $isLogged = !empty($this->context->cookie->logged) || !empty($this->context->cookie->passwd);
            if ($isLogged) {
                return (int) $this->context->cookie->id_customer;
            }
        }

        return 0;
    }

    private function getRequestProductId($controller): int
    {
        $idProduct = (int) Tools::getValue('id_product');
        if ($idProduct > 0) {
            return $idProduct;
        }

        if (is_object($controller)) {
            if (property_exists($controller, 'id_product') && (int) $controller->id_product > 0) {
                return (int) $controller->id_product;
            }

            if (property_exists($controller, 'product') && is_object($controller->product) && !empty($controller->product->id)) {
                return (int) $controller->product->id;
            }
        }

        return 0;
    }

    private function getProductIdFromRequestUri(): int
    {
        $path = isset($_SERVER['REQUEST_URI']) ? (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
        if ($path === '') {
            return 0;
        }

        $segments = explode('/', trim($path, '/'));
        foreach ($segments as $segment) {
            if (preg_match('/^(\d+)(?:-(.+))?$/', $segment, $m)) {
                $idProduct = (int) $m[1];
                $slug = isset($m[2]) ? (string) $m[2] : '';
                if ($this->productUrlMatchesCurrentShop($idProduct, $slug)) {
                    return $idProduct;
                }
            }
        }

        return 0;
    }

    private function productUrlMatchesCurrentShop(int $idProduct, string $slug): bool
    {
        if ($idProduct <= 0) {
            return false;
        }

        $idShop = (int) $this->context->shop->id;
        $idLang = (int) $this->context->language->id;
        $sql = 'SELECT pl.`link_rewrite`
            FROM `' . bqSQL(_DB_PREFIX_ . 'product_shop') . '` ps
            LEFT JOIN `' . bqSQL(_DB_PREFIX_ . 'product_lang') . '` pl
                ON (pl.`id_product` = ps.`id_product` AND pl.`id_shop` = ps.`id_shop` AND pl.`id_lang` = ' . (int) $idLang . ')
            WHERE ps.`id_product` = ' . (int) $idProduct . '
              AND ps.`id_shop` = ' . (int) $idShop . '
            LIMIT 1';

        $linkRewrite = (string) Db::getInstance()->getValue($sql);
        if ($linkRewrite === '') {
            return false;
        }

        return $slug === '' || $slug === $linkRewrite;
    }

    private function extractProductIds(array $products): array
    {
        $ids = [];
        foreach ($products as $product) {
            if (isset($product['id_product'])) {
                $ids[] = (int) $product['id_product'];
            }
        }

        return array_values(array_unique($ids));
    }

    private function logBlocked(string $event, int $idCustomer, int $idProduct): void
    {
        if (!(int) Configuration::get(self::CFG_DEBUG)) {
            return;
        }

        $ip = (string) Tools::getRemoteAddr();
        $idShop = (int) $this->context->shop->id;
        $msg = sprintf(
            '[svprivateproducts] blocked=%s shop=%d customer=%d product=%d ip=%s',
            $event,
            $idShop,
            $idCustomer,
            $idProduct,
            $ip
        );

        PrestaShopLogger::addLog($msg, 1, null, 'Product', $idProduct, true);
    }

    private function installTab(): bool
    {
        if ((int) Tab::getIdFromClassName('AdminSvprivateproducts') > 0) {
            return true;
        }

        $parentId = (int) Tab::getIdFromClassName('AdminCatalog');
        if ($parentId <= 0) {
            $parentId = 0;
        }

        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminSvprivateproducts';
        $tab->module = $this->name;
        $tab->id_parent = $parentId;

        $names = [];
        foreach (Language::getLanguages(false) as $lang) {
            $names[(int) $lang['id_lang']] = 'Private products';
        }
        $tab->name = $names;

        return (bool) $tab->add();
    }

    private function uninstallTab(): bool
    {
        $idTab = (int) Tab::getIdFromClassName('AdminSvprivateproducts');
        if ($idTab <= 0) {
            return true;
        }

        $tab = new Tab($idTab);
        return (bool) $tab->delete();
    }

    private function clearModuleCache(): void
    {
        // No templates cached here, but keep a single place for future caching.
        // Also helps to enforce the requirement "limpiar cache del modulo".
        $this->_clearCache('*');
    }
}

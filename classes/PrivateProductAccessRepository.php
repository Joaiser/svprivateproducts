<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

class SvPrivateProductsPrivateProductAccessRepository
{
    public const TABLE = 'sv_private_product_access';

    /** @return string */
    public static function tableName()
    {
        return _DB_PREFIX_ . self::TABLE;
    }

    /**
     * Returns true if product has at least one active relation in this shop.
     *
     * @param int $idProduct
     * @param int $idShop
     *
     * @return bool
     */
    public static function isProductPrivate($idProduct, $idShop)
    {
        $idProduct = (int) $idProduct;
        $idShop = (int) $idShop;

        $sql = 'SELECT 1 FROM `' . bqSQL(self::tableName()) . '`
            WHERE `id_shop` = ' . $idShop . '
              AND `id_product` = ' . $idProduct . '
              AND `active` = 1';

        return (bool) Db::getInstance()->getValue($sql);
    }

    /**
     * Returns true if customer has an active relation for this product in this shop.
     *
     * @param int $idCustomer
     * @param int $idProduct
     * @param int $idShop
     *
     * @return bool
     */
    public static function isCustomerAllowed($idCustomer, $idProduct, $idShop)
    {
        $idCustomer = (int) $idCustomer;
        $idProduct = (int) $idProduct;
        $idShop = (int) $idShop;

        if ($idCustomer <= 0) {
            return false;
        }

        $sql = 'SELECT 1 FROM `' . bqSQL(self::tableName()) . '`
            WHERE `id_shop` = ' . $idShop . '
              AND `id_product` = ' . $idProduct . '
              AND `id_customer` = ' . $idCustomer . '
              AND `active` = 1';

        return (bool) Db::getInstance()->getValue($sql);
    }

    /**
     * Public products are always accessible.
     * Private products are only accessible for allowed customers.
     *
     * @param int $idCustomer
     * @param int $idProduct
     * @param int $idShop
     *
     * @return bool
     */
    public static function canCustomerAccessProduct($idCustomer, $idProduct, $idShop)
    {
        if (!self::isProductPrivate($idProduct, $idShop)) {
            return true;
        }

        return self::isCustomerAllowed($idCustomer, $idProduct, $idShop);
    }

    /**
     * Filter a products array (from ProductSearch) removing private products
     * not allowed for the current customer.
     *
     * Expected shape: each element has `id_product`.
     *
     * @param array $products
     * @param int $idCustomer
     * @param int $idShop
     *
     * @return array
     */
    public static function filterProductsForCustomer(array $products, $idCustomer, $idShop)
    {
        $idCustomer = (int) $idCustomer;
        $idShop = (int) $idShop;

        if (empty($products)) {
            return $products;
        }

        $ids = [];
        foreach ($products as $p) {
            if (isset($p['id_product']) && Validate::isUnsignedId($p['id_product'])) {
                $ids[] = (int) $p['id_product'];
            }
        }
        $ids = array_values(array_unique($ids));
        if (empty($ids)) {
            return $products;
        }

        // Fetch all private product ids (active) among the given ids for this shop.
        $sql = 'SELECT DISTINCT `id_product`
            FROM `' . bqSQL(self::tableName()) . "`\n" .
            'WHERE `id_shop` = ' . $idShop . '
              AND `active` = 1
              AND `id_product` IN (' . implode(',', array_map('intval', $ids)) . ')';
        $privateRows = Db::getInstance()->executeS($sql);
        if (empty($privateRows)) {
            return $products;
        }

        $privateIds = [];
        foreach ($privateRows as $r) {
            $privateIds[(int) $r['id_product']] = true;
        }

        // If no customer logged in, remove all private products.
        if ($idCustomer <= 0) {
            return array_values(array_filter($products, static function ($p) use ($privateIds) {
                $id = isset($p['id_product']) ? (int) $p['id_product'] : 0;
                return $id <= 0 || !isset($privateIds[$id]);
            }));
        }

        // Fetch allowed private products for this customer.
        $sqlAllowed = 'SELECT `id_product`
            FROM `' . bqSQL(self::tableName()) . "`\n" .
            'WHERE `id_shop` = ' . $idShop . '
              AND `active` = 1
              AND `id_customer` = ' . $idCustomer . '
              AND `id_product` IN (' . implode(',', array_keys($privateIds)) . ')';
        $allowedRows = Db::getInstance()->executeS($sqlAllowed);
        $allowed = [];
        foreach ($allowedRows as $r) {
            $allowed[(int) $r['id_product']] = true;
        }

        return array_values(array_filter($products, static function ($p) use ($privateIds, $allowed) {
            $id = isset($p['id_product']) ? (int) $p['id_product'] : 0;
            if ($id <= 0) {
                return true;
            }
            if (!isset($privateIds[$id])) {
                return true;
            }
            return isset($allowed[$id]);
        }));
    }
}

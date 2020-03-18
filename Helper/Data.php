<?php

namespace CodingMice\VsBridgeIndexerExtension\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

/**
 * Class Data
 */
class Data extends AbstractHelper
{
    /**
     * Local paths for saving adsitional indexer data config
     */
    const XPATH_ADDITIONAL_INDEXER_DATA_ENABLE = "additional_indexer_data/category_configuration/enable";
    const XPATH_ROOM_CATEGORY_ID = "additional_indexer_data/category_configuration/room_category_id";
    const XPATH_COLLECTION_CATEGORY_ID = "additional_indexer_data/category_configuration/collection_category_id";

    /**
     * Get additional indexer data is enabled or not
     *
     * @param int|null $storeId
     *
     * @return bool
     */
    public function getAdditionalIndexerDataEnable($storeId = null)
    {
        return $this->scopeConfig->getValue(
            self::XPATH_ADDITIONAL_INDEXER_DATA_ENABLE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get room category id
     *
     * @param int|null $storeId
     *
     * @return int
     */
    public function getRoomCategoryId($storeId = null)
    {
        return $this->scopeConfig->getValue(
            self::XPATH_ROOM_CATEGORY_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get collection category id
     *
     * @param int|null $storeId
     *
     * @return int
     */
    public function getCollectionCategoryId($storeId = null)
    {
        return $this->scopeConfig->getValue(
            self::XPATH_COLLECTION_CATEGORY_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}

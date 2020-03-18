<?php

namespace CodingMice\VsBridgeIndexerExtension\Model\AdditionalData;

use CodingMice\VsBridgeIndexerExtension\Helper\Data;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Select;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

/**
 * Class CategoryNames
 */
class CategoryNames extends DataObject
{
    /**
     * Additional Data Helper
     *
     * @var Data
     */
    protected $additionalDataHelper;

    /**
     * Magento category repository interface
     *
     * @var CategoryRepositoryInterface
     */
    protected $categoryrepository;

    /**
     * Magento resource connection
     *
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * Established connection object
     */
    private $connection = null;

    /**
     * CategoryNames constructor.
     *
     * @param Data $additionalDataHelper
     * @param CategoryRepositoryInterface $categoryRepository
     * @param ResourceConnection $resourceConnection
     * @param array $data
     */
    public function __construct(
        Data $additionalDataHelper,
        CategoryRepositoryInterface $categoryRepository,
        ResourceConnection $resourceConnection,
        array $data = []
    ) {
        parent::__construct($data);

        $this->additionalDataHelper = $additionalDataHelper;
        $this->categoryrepository = $categoryRepository;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Get child category data
     *
     * @param int $categoryId
     * @param int $storeId
     *
     * @return array $subCategoryList
     */
    public function getChildCategories($categoryId, $storeId)
    {
        try {
            $category = $this->categoryrepository->get($categoryId, $storeId);
            $subCategoryList = $this->loadChildCategoryCollection($category);
        } catch (\Exception $e) {
            $subCategoryList = [];
        }

        return $subCategoryList;
    }

    /**
     * Loading subcategory list
     *
     * @param obj $category
     *
     * @return obj
     */
    public function loadChildCategoryCollection($category)
    {
        return $category->getChildrenCategories()->toArray(['name']);
    }

    /**
     * Preparing additional inderex data
     *
     * @param array $productIds
     * @param array $indexData
     * @param int $storeId
     *
     * @return array $indexData
     */
    public function prepareAditionalIndexerData($productIds, $indexData, $storeId, $productType)
    {
        $isAdditionalCategoryDataEnable = $this->additionalDataHelper->getAdditionalIndexerDataEnable();
        if ($isAdditionalCategoryDataEnable && !empty($productIds)) {
            $roomCategoryId = $this->additionalDataHelper->getRoomCategoryId();
            $collectionCategoryId = $this->additionalDataHelper->getCollectionCategoryId();
            $roomSubcategoryData = $this->getChildCategories($roomCategoryId, $storeId);
            $collectionSubcategoryData = $this->getChildCategories($collectionCategoryId, $storeId);
            $productCategoryData = $this->getCategoryIdForProducts($productIds, $storeId);

            foreach ($indexData as $productId => $indexDataItem) {
                if ($indexDataItem['type_id'] != $productType) {
                    continue;
                }

                if (!empty($roomSubcategoryData) || !empty($collectionSubcategoryData)) {
                    $productCategoryIds = $productCategoryData[$productId] ?? '';
                    $productCategoryIds = explode(',', $productCategoryIds);

                    if (!empty($productCategoryIds)) {
                        if (!empty($roomSubcategoryData)) {
                            $roomCategoryIds = array_keys($roomSubcategoryData);
                            $validRoomChildCategorys = array_intersect_key($roomSubcategoryData, array_flip($productCategoryIds));
                            $indexData[$productId]['rooms_names'] = array_column($validRoomChildCategorys, 'name');
                        } else {
                            $indexData[$productId]['rooms_names'] = [];
                        }
                        if (!empty($collectionSubcategoryData)) {
                            $collectionCategoryIds = array_keys($collectionSubcategoryData);
                            $validCollectionChildCategorys = array_intersect_key($collectionSubcategoryData, array_flip($productCategoryIds));
                            $indexData[$productId]['collections_names'] = array_column($validCollectionChildCategorys, 'name');
                        } else {
                            $indexData[$productId]['collections_names'] = [];
                        }
                    } else {
                        $indexData[$productId]['rooms_names'] = [];
                        $indexData[$productId]['collections_names'] = [];
                    }
                }
            }
        }

        return $indexData;
    }

    /**
     * Get category ids for product ids
     *
     * @param array $productIds
     *
     * @return array $result
     */
    public function getCategoryIdForProducts($productIds)
    {
        try {
            $connection = $this->getResourceConnection();
            $select = $connection->select();
            $categoryProductTableName = $this->resourceConnection->getTableName('catalog_category_product');
            $select->from($categoryProductTableName, ['product_id']);
            $select->columns(['category_ids' => new \Zend_Db_Expr('GROUP_CONCAT(category_id)')]);
            $select->where('product_id IN(?)', $productIds);
            $select->group('product_id');

            $result = $connection->fetchAll($select);
            $productIdList = array_column($result, 'product_id');
            $categoryIdsList = array_column($result, 'category_ids');
            $finalResult = array_combine( $productIdList, $categoryIdsList );

        } catch (\Exception $exception) {
            $finalResult = [];
        }

        return $finalResult;
    }

    /**
     * Get resource connection
     *
     * @return \Magento\Framework\DB\Adapter\AdapterInterface|null
     */
    private function getResourceConnection()
    {
        if (is_null($this->connection)) {
            $this->connection = $this->resourceConnection->getConnection();
        }

        return $this->connection;
    }
}

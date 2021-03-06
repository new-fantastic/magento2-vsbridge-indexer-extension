<?php

namespace CodingMice\VsBridgeIndexerExtension\Plugin\Catalog\Model\Indexer\DataProvider\Product;

use CodingMice\VsBridgeIndexerExtension\Model\AdditionalData\CategoryNames;
use Divante\VsbridgeIndexerCatalog\Model\ResourceModel\Product\Category as CategoryResource;
use Divante\VsbridgeIndexerCatalog\Model\ResourceModel\Product\Links as LinkResourceModel;
use Divante\VsbridgeIndexerCatalog\Model\Indexer\DataProvider\Product\ConfigurableData;
use Divante\VsbridgeIndexerCatalog\Model\Indexer\DataProvider\Product\MediaGalleryData;
use Divante\VsbridgeIndexerCore\Api\DataProviderInterface;
use Divante\VsbridgeIndexerCore\Api\IndexOperationInterface;
use Divante\VsbridgeIndexerCore\Console\Command\RebuildEsIndexCommand;
use Divante\VsbridgeIndexerCore\Config\IndicesSettings;
use Egits\VsbridgeIndexerCatalog\Helper\Config as ConfigHelper;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator;
use Magento\Framework\App\ObjectManager;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Divante\VsbridgeIndexerCatalog\Model\Attribute\LoadOptionLabelById;
use Divante\VsbridgeIndexerCore\Indexer\GenericIndexerHandler;
use Divante\VsbridgeIndexerCore\Cache\Processor as CacheProcessor;
use Egits\Catalog\Helper\Product\Attributes\Custom as CustomProductAttributes;
use Egits\VsbridgeIndexerCatalog\Model\Indexer\DataProvider\Product as CustomProductData;
use Egits\VsbridgeIndexerCatalog\Helper\Product\Attribute\LabelKey as AttributeLabelKey;

class ConfigurableDataExtender {

    protected $objectManager;

    /* @var LoadOptionById $loadOptionById */
    private $loadOptionById;

    /* @var CategoryResource $categoryResource */
    private $categoryResource;

    /* variable to cache locale for each store */
    private $storeLocales = [];

    /**
     * Loaded configurable product ids
     *
     * @var array
     */
    protected $loadedConfigurableIds = [];

    /**
     * Prepare category name
     *
     * @var CategoryNames
     */
    protected $categoryNames;

    /**
     * Magento product repository
     *
     * @var ProductRepositoryInterface
     */
    protected $productRepositoryInterface;

    /**
     * Vsbridge index handler
     *
     * @var GenericIndexerHandler
     */
    protected $indexerHandler;

    /**
     * Vsbridge cache processor
     *
     * @var CacheProcessor
     */
    protected $cacheProcessor;

    /**
     * Magento store manager
     *
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * Divante VsbridgeIndexerCatalog Link Resource Model
     *
     * @var LinkResourceModel
     */
    protected $linkResourceModel;

    /**
     * Egits custom product data
     *
     * @var CustomProductData
     */
    protected $customProductData;

    /**
     * ConfigurableDataExtender constructor.
     *
     * @param \Divante\VsbridgeIndexerCatalog\Model\Attribute\LoadOptionById $loadOptionById
     * @param CategoryNames $categoryNames
     * @param ProductRepositoryInterface $productRepositoryInterface
     * @param CacheProcessor $cacheProcessor
     * @param StoreManagerInterface $storeManager
     * @param GenericIndexerHandler $indexHandler
     * @param CustomProductData $customProductData
     */
    public function __construct(
        \Divante\VsbridgeIndexerCatalog\Model\Attribute\LoadOptionById $loadOptionById,
        CategoryNames $categoryNames,
        ProductRepositoryInterface $productRepositoryInterface,
        CacheProcessor $cacheProcessor,
        StoreManagerInterface $storeManager,
        GenericIndexerHandler $indexerHandler,
        LinkResourceModel $linkResourceModel,
        CustomProductData $customProductData
    ) {
        $this->loadOptionById = $loadOptionById;
        $this->objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->categoryNames = $categoryNames;
        $this->productRepositoryInterface = $productRepositoryInterface;
        $this->cacheProcessor = $cacheProcessor;
        $this->storeManager = $storeManager;
        $this->indexerHandler = $indexerHandler;
        $this->linkResourceModel = $linkResourceModel;
        $this->customProductData = $customProductData;
    }

    /**
     * This method will take ES docs prepared by Divante Extension and modify them
     * before they are added to ES in \Divante\VsbridgeIndexerCore\Indexer\GenericIndexerHandler::saveIndex
     * @see: \Divante\VsbridgeIndexerCatalog\Model\Indexer\DataProvider\Product\ConfigurableData::addData
     */
    public function afterAddData(ConfigurableData $subject, $docs, $indexData, $storeId){
        $docs = $this->extendDataWithGallery($subject, $docs,$storeId);

        /* @var \Divante\VsbridgeIndexerCore\Index\IndexOperations $indexOperations */
        $this->categoryResource = $this->objectManager->create("Divante\VsbridgeIndexerCatalog\Model\ResourceModel\Product\Category");

        $docs = $this->addDiscountAmount($docs, $storeId);

        $docs = $this->categoryNames->prepareAditionalIndexerData($this->loadedConfigurableIds, $docs, $storeId, 'simple');

        $docs = $this->cloneConfigurableColors($docs,$storeId);

        $docs = $this->addHreflangUrls($docs);

        // $docs = $this->extendDataWithCategoryNew($docs,$storeId);

        return $docs;
    }

    private function cloneConfigurableColors($indexData,$storeId)
    {
        $clones = [];
        $productRewrites = $this->objectManager->create(ProductUrlPathGenerator::class);

        foreach ($indexData as $product_id => $indexDataItem) {

            // if ($indexDataItem['type_id'] == 'bundle') {
            //     if ($indexDataItem['product_collection']) {
            //         $product_collection_option = $this->loadOptionById->execute(
            //             'product_collection',
            //             $indexDataItem['product_collection'],
            //             $storeId
            //         );
            //         $indexDataItem['product_collection_label'] = $product_collection_option['label'];
            //     }
            //     $indexDataItem['slug_from_name'] = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $indexDataItem['name'])));
            //     continue;
            // }

            if ($indexDataItem['type_id'] !== 'configurable') {
                continue;
            }

            if ( ! isset($indexDataItem['configurable_options']) ) {
                continue;
            }

            $has_colors = false;
            $colors = null;
            foreach ($indexDataItem['configurable_children'] as $child) {
                /**
                 * Preparing configurable product ids
                 */
                $clone_color_option = [];
                $clone_size_option = [];
                $cloneId = $indexDataItem['id'].'-'.$child['color'].'-'.$child['size'];
                $clones[$cloneId] = $indexDataItem;
                $clones[$cloneId]['clone_color_id'] = $child['color'];
                $clones[$cloneId]['clone_size_id'] = $child['size'];
                $clones[$cloneId]['sku'] = $indexDataItem['sku'].'-'.$child['color'].'-'.$child['size'];
                $clones[$cloneId]['originalParentSku'] = $indexDataItem['sku'];
                if (isset($child['color']) && $child['color'] != null) {
                    $clone_color_option = $this->loadOptionById->execute(
                        'color',
                        $clones[$cloneId]['clone_color_id'],
                        $storeId
                    );
                }

                if (isset($child['size']) && $child['size'] != null) {
                    $clone_size_option = $this->loadOptionById->execute(
                        'size',
                        $clones[$cloneId]['clone_size_id'],
                        $storeId
                    );
                }
                $clones[$cloneId]['clone_color_label'] = $clone_color_option['label'] ?? '';
                $clones[$cloneId]['clone_size_label'] = $clone_size_option['label'] ?? '';
                // $clones[$cloneId]['url_key'] = $indexDataItem['url_key'].'?color='.$clone_color;
                $clones[$cloneId]['clone_tile_name'] = $child['name'].', '.$clones[$cloneId]['clone_size_label'];
                $clones[$cloneId]['clone_name'] = $child['name'].', '.$clones[$cloneId]['clone_color_label'].', '.$clones[$cloneId]['clone_size_label'];
                $rooms = $child['rooms_names'] ?? [];
                $collections = $child['collections_names'] ?? [];
                $clones[$cloneId]['rooms_names'] = $rooms;
                $clones[$cloneId]['collections_names'] = $collections;
                $collection = $child['collections_names'][0] ?? '';
                $clones[$cloneId]['collection_name'] = $collection;
                if (strlen($collection) > 0) {
                    $collection .= ' ';
                }
                // $clones[$cloneId]['full_child'] = isset($indexData[intval($child['id'])]) ? $indexData[intval($child['id'])] : [];
                $clones[$cloneId]['slug_from_name'] = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $collection.$clones[$cloneId]['clone_name'])));
                $clones[$cloneId]['clone_of'] = $child['sku'];
                $clones[$cloneId]['is_clone'] = 2;

                $category_data =  $this->getCategoryData($storeId, $child['id']);
                $clones[$cloneId][ConfigHelper::PRODUCT_SORT_ORDER_CATEGORY] = $category_data['category_new'];
                $clones[$cloneId]['category'] = $category_data['category'];
                $clones[$cloneId]['category_ids'] = $category_data['category_ids'];

                // Preparing "tag" attribute data for clone
                if (isset($clones[$cloneId][CustomProductAttributes::CATALOG_PRODUCT_TAG_ATTRIBUTE])) {
                    unset($clones[$cloneId][CustomProductAttributes::CATALOG_PRODUCT_TAG_ATTRIBUTE]);
                }
                if (isset($clones[$cloneId][AttributeLabelKey::TAG_ATTRIBUTE_VALUE_LABEL_KEY])) {
                    unset($clones[$cloneId][AttributeLabelKey::TAG_ATTRIBUTE_VALUE_LABEL_KEY]);
                }
                if (isset($child[CustomProductAttributes::CATALOG_PRODUCT_TAG_ATTRIBUTE])) {
                    $clones[$cloneId][CustomProductAttributes::CATALOG_PRODUCT_TAG_ATTRIBUTE] = $child[CustomProductAttributes::CATALOG_PRODUCT_TAG_ATTRIBUTE];
                    $clones[$cloneId] = $this->customProductData->processCustomAttributes($clones[$cloneId], $storeId);
                }

                // Get url_key from the child
                if (isset($child['sku'])) {
                    $productRepository = $this->objectManager->create(ProductRepositoryInterface::class);
                    $product = $productRepository->get($child['sku'], false, $storeId);
                    if (isset($product['url_key'])) {
                        $clones[$cloneId]['url_key'] = $product['url_key'];
                    }

                    // Product links for clone products from child products
                    if ($product->getProductLinks()) {
                        $child['entity_id'] = $child['id'];
                        $this->linkResourceModel->clear();
                        $this->linkResourceModel->setProducts([$child]);
                        $clones[$cloneId]['product_links'] = $this->linkResourceModel->getLinkedProduct($child);
                        $this->linkResourceModel->clear();
                    } else {
                        $clones[$cloneId]['product_links'] = [];
                    }
                }

                $keys = array(
                    'final_price_incl_tax',
                    'final_price',
                    'price_incl_tax',
                    'regular_price',
                    'status',
                    'visibility',
                    'size',
                    'color'
                );

                foreach ($keys as $key) {
                    if (isset($child[$key])) {
                        $clones[$cloneId][$key] = $child[$key];
                    }
                }
            }

            $this->invalidateDisabledChildProducts($indexDataItem, $storeId);

            // foreach ($indexDataItem['configurable_options'] as $option) {
            //     if ( $option['attribute_code'] === 'color' ) {
            //         /**
            //          * For some reason, product configurations can be added without adding values in the configurable,
            //          * make sure values exist
            //          */
            //         if(!empty($option['values'])) {
            //             $has_colors = true;
            //             $colors = $option['values'];
            //         }
            //         break;
            //     }
            // }

            // if ( !$has_colors) {
            //     $cloneId = $this->getIdForClonedItem($indexDataItem);
            //     $clones[$cloneId] = $indexDataItem;

            //     if(!empty($indexDataItem['color'])){
            //         $clones[$cloneId]['clone_color_id'] = isset($indexDataItem['color']) ? $indexDataItem['color'] : $indexDataItem['configurable_children'][0]['color'];
            //         $clones[$cloneId]['sku'] = $indexDataItem['sku'].'-'.$indexDataItem['color'];
            //         $clone_color_option = $this->loadOptionById->execute(
            //             'color',
            //             $clones[$cloneId]['clone_color_id'],
            //             $storeId
            //         );
            //         $clones[$cloneId]['clone_color_label'] = $clone_color_option['label'];
            //         $clones[$cloneId]['url_key'] = $indexDataItem['url_key'].'?color='.$clone_color;
            //         $clones[$cloneId]['clone_name'] = $indexDataItem['name'].' '.$clones[$cloneId]['clone_color_label'];

            //         // if ($clones[$cloneId]['product_collection']) {
            //         //     $product_collection_option = $this->loadOptionById->execute(
            //         //         'product_collection',
            //         //         $clones[$cloneId]['product_collection'],
            //         //         $storeId
            //         //     );
            //         //     $clones[$cloneId]['product_collection_label'] = $product_collection_option['label'];
            //         // }

            //         $clones[$cloneId]['slug_from_name'] = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $clones[$cloneId]['clone_name'])));


            //     } else {
            //         $clones[$cloneId]['sku'] = $indexDataItem['sku'];
            //     }

            //     $clones[$cloneId]['size_in_color_options'] = $clones[$cloneId]['size_options'];
            //     $clones[$cloneId]['is_clone'] = 2;

            // } else {
            //     if(!empty($colors)){
            //         foreach ($colors as $color) {
            //             $clone_color = strtolower(str_ireplace(' ', '-', $color['label']));
            //             $cloneId = $product_id.'-'.$color['value_index'];
            //             $clones[$cloneId] = $indexDataItem;
            //             $clones[$cloneId]['clone_color_label'] = $color['label'];
            //             $clones[$cloneId]['clone_color_id'] = $color['value_index'];
            //             $clones[$cloneId]['sku'] = $indexDataItem['sku'].'-'.$color['value_index'];
            //             $clones[$cloneId]['is_clone'] = 1;
            //             $clones[$cloneId]['url_key'] = $indexDataItem['url_key'].'?color='.$clone_color;
            //             $clones[$cloneId]['clone_name'] = $indexDataItem['name'].' '.$color['label'];
            //             // if ($clones[$cloneId]['product_collection']) {
            //             //     $product_collection_option = $this->loadOptionById->execute(
            //             //         'product_collection',
            //             //         $clones[$cloneId]['product_collection'],
            //             //         $storeId
            //             //     );
            //             //     $clones[$cloneId]['product_collection_label'] = $product_collection_option['label'];
            //             // }

            //             // Set final price from lowest price from child in color
            //             $keys = array(
            //                 'final_price_incl_tax',
            //                 'final_price',
            //                 'price_incl_tax',
            //                 'regular_price'
            //             );

            //             $values = array(
            //                 'final_price_incl_tax' => -1,
            //                 'final_price' => -1,
            //                 'price_incl_tax' => -1,
            //                 'regular_price' => -1
            //             );

            //             if (array_key_exists('configurable_children', $clones[$cloneId]) && is_iterable($clones[$cloneId]['configurable_children'])) {
            //                 foreach ($clones[$cloneId]['configurable_children'] as $child) {
            //                     if ($child['color'] != $color['value_index']) {
            //                         continue;
            //                     }

            //                     // size_in_color_options stuff
            //                     if (!isset($clones[$cloneId]['size_in_color_options'] )) {
            //                         $clones[$cloneId]['size_in_color_options'] = [];
            //                     }
            //                     if (isset($child['size'])) {
            //                         $clones[$cloneId]['size_in_color_options'][] = intval($child['size']);
            //                     }
            //                     // Price stuff
            //                     foreach($keys as $key) {
            //                         if (isset($child[$key])) {
            //                             if ($child[$key] > 0 && ($values[$key] == -1 || $child[$key] < $values[$key])) {
            //                                 $values[$key] = $child[$key];
            //                             }
            //                         }
            //                     }
            //                 }
            //             }

            //             if (isset($clones[$cloneId]['size_in_color_options'] )) {
            //                 $clones[$cloneId]['size_in_color_options'] = array_unique($clones[$cloneId]['size_in_color_options']);
            //             }

            //             foreach($values as $key => $value) {
            //                 if ($value > 0) {
            //                     $clones[$cloneId][$key] = $value;
            //                 }
            //             }

            //             $clones[$cloneId]['slug_from_name'] = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $clones[$cloneId]['clone_name'])));

            //         }
            //     }
            // }
        }

        return $indexData + $clones;
    }

    private function getCategoryData($storeId,$productId){

        $categories =  $this->categoryResource->loadCategoryData($storeId, [$productId]);
        $category_data = [
            'category' => [],
            'category_new' => [],
            'category_ids' => []
        ];

        foreach ($categories as $cat) {
            $cat_id = (int) $cat["category_id"];
            $cat_postion = (int) $cat['position'];

            $category_data['category'][] = [
                'category_id' => $cat_id,
                'name' => (string)$cat['name'],
                'position' => $cat_postion,
            ];
            $category_data['category_new'][$cat_id] = $cat_postion;
            $category_data['category_ids'][] = $cat_id;
        }

        return $category_data;
    }

    private function extendDataWithGallery(\Divante\VsbridgeIndexerCatalog\Model\Indexer\DataProvider\Product\ConfigurableData $subject, $docs,$storeId)
    {

        /* make this work here */
        $mediaGalleryDataProvider = $this->objectManager->create(MediaGalleryData::class);

        $configurableResource = $subject->getConfigurableResource();
        $configurableResource->setProducts($docs);

        $allChildren = $configurableResource->getSimpleProducts($storeId);

        if (null === $allChildren) {
            return $docs;
        }

        $stockRowData = $subject->getLoadInventory()->execute($allChildren, $storeId);
        $configurableAttributeCodes = $subject->getConfigurableResource()->getConfigurableAttributeCodes();

        $allChildren = $subject->getChildrenAttributeProcessor()
            ->execute($storeId, $allChildren, $configurableAttributeCodes);

        // add Media Gallery
        $allChildren = $mediaGalleryDataProvider->addData($allChildren, $storeId);

        foreach ($allChildren as $childKey => $child) {

            $childId = $child['entity_id'];
            $child['id'] = (int) $childId;
            $parentIds = $child['parent_ids'];

            if (!isset($child['regular_price']) && isset($child['price'])) {
                $child['regular_price'] = $child['price'];
            }

            if (isset($stockRowData[$childId])) {
                $productStockData = $stockRowData[$childId];

                unset($productStockData['product_id']);
                $productStockData = $subject->getInventoryProcessor()->prepareInventoryData($storeId, $productStockData);
                $child['stock'] = $productStockData;
            }

            foreach ($parentIds as $parentId) {
                $child = $subject->filterData($child);

                if (!isset($docs[$parentId]['configurable_options'])) {
                    $docs[$parentId]['configurable_options'] = [];
                }

                $docs[$parentId] = $this->replaceOriginalChild($docs[$parentId],$child);
            }
        }

        $allChildren = null;

        return $docs;
    }

    // private function extendDataWithCategoryNew($indexData,$storeId)
    // {
    //     foreach ($indexData as $product_id => $indexDataItem) {

    //         if ($indexData[$product_id]['type_id'] !== 'configurable') {
    //             continue;
    //         }

    //         if ( ! isset($indexData[$product_id]['configurable_options']) ) {
    //             continue;
    //         }

    //         $has_colors = false;
    //         $colors = null;
    //         foreach ($indexData[$product_id]['configurable_options'] as $option) {
    //             if ( $option['attribute_code'] === 'color' ) {
    //                 /**
    //                  * For some reason, product configurations can be added without adding values in the configurable,
    //                  * make sure values exist
    //                  */
    //                 if(!empty($option['values'])) {
    //                     $has_colors = true;
    //                     $colors = $option['values'];
    //                 }
    //                 break;
    //             }
    //         }

    //         if ( !$has_colors) {
    //             $wasChildInThisColor = false;

    //             foreach($indexData[$product_id]['configurable_children'] as $child_data) {

    //                 if (!$wasChildInThisColor) {
    //                     $wasChildInThisColor = true;

    //                     $category_data =  $this->getCategoryData($storeId, $child_data['id']);
    //                     $indexData[$product_id]['category_new'] = $category_data['category_new'];
    //                     $indexData[$product_id]['category'] = $category_data['category'];

    //                     continue;
    //                 } else {
    //                     //loop through the children and get the values of the smallest size child with the same color
    //                     $categories_data =  $this->getCategoryData($storeId, $child_data['id']);
    //                     foreach ($categories_data['category_new'] as $category_id => $valueToCheck) {
    //                         if (!isset($indexData[$product_id]['category_new'])) {
    //                             // Is it even possible?
    //                             continue;
    //                         }
    //                         $currentValue = isset($indexData[$product_id]['category_new'][$category_id]) ? $indexData[$product_id]['category_new'][$category_id] : 0;
    //                         // If new value is 0, do nothing
    //                         if ($valueToCheck == 0) {
    //                             continue;
    //                         }
    //                         // If current value is 0, and new is not 0. Set it
    //                         if ($currentValue == 0) {
    //                             // $clones[$cloneId]['category'][$category_id] = $valueToCheck;
    //                             $indexData[$product_id]['category_new'][$category_id] = $valueToCheck;
    //                             continue;
    //                         }

    //                         // If both are none 0, compare
    //                         if ($valueToCheck < $currentValue) {
    //                             // $clones[$cloneId]['category'][$category_id] = $valueToCheck;
    //                             $indexData[$product_id]['category_new'][$category_id] = $valueToCheck;
    //                             continue;
    //                         }
    //                     }
    //                 }
    //             }

    //         } else {
    //             if(!empty($colors)){
    //                 foreach ($colors as $color) {

    //                     $wasChildInThisColor = false;
    //                     //loop through the children and get the values of the smallest size child with the same color
    //                     foreach($indexData[$product_id]['configurable_children'] as $child_data) {
    //                         if(!empty($child_data['color']) && $child_data['color'] == $color['value_index']){

    //                             if (!$wasChildInThisColor) {
    //                                 $wasChildInThisColor = true;

    //                                 $category_data =  $this->getCategoryData($storeId, $child_data['id']);
    //                                 $indexData[$product_id]['category_new'] = $category_data['category_new'];
    //                                 $indexData[$product_id]['category'] = $category_data['category'];
    //                                 continue;
    //                             } else {
    //                                 $categories_data =  $this->getCategoryData($storeId, $child_data['id']);
    //                                 foreach ($categories_data['category_new'] as $category_id => $valueToCheck) {
    //                                     if (!isset($indexData[$product_id]['category_new'])) {
    //                                         // Is it even possible?
    //                                         continue;
    //                                     }
    //                                     $currentValue = isset($indexData[$product_id]['category_new'][$category_id]) ? $indexData[$product_id]['category_new'][$category_id] : 0;
    //                                     // If new value is 0, do nothing
    //                                     if ($valueToCheck == 0) {
    //                                         continue;
    //                                     }
    //                                     // If current value is 0, and new is not 0. Set it
    //                                     if ($currentValue == 0) {
    //                                         // $clones[$cloneId]['category'][$category_id] = $valueToCheck;
    //                                         $indexData[$product_id]['category_new'][$category_id] = $valueToCheck;
    //                                         continue;
    //                                     }

    //                                     // If both are none 0, compare
    //                                     if ($valueToCheck < $currentValue) {
    //                                         // $clones[$cloneId]['category'][$category_id] = $valueToCheck;
    //                                         $indexData[$product_id]['category_new'][$category_id] = $valueToCheck;
    //                                         continue;
    //                                     }
    //                                 }
    //                             }

    //                         }
    //                     }

    //                 }
    //             }
    //         }

    //     }
    //     return $indexData;
    // }

    // /**
    //  * @param $indexDataItem
    //  * @return string
    //  */
    // private function getIdForClonedItem($indexDataItem): string
    // {
    //     if (!empty($indexDataItem['color'])) {
    //         $cloneId = $indexDataItem['id'] . '-' . $indexDataItem['color'];
    //     } else {
    //         $cloneId = $indexDataItem['id'];
    //     }
    //     return (string) $cloneId;
    // }

    private function addHreflangUrls($indexData)
    {
        $storeManager = $this->objectManager->create("\Magento\Store\Model\StoreManager");
        $stores = $storeManager->getStores();
        $websiteManager = $this->objectManager->create("\Magento\Store\Model\Website");
        $configReader = $this->objectManager->create(\Magento\Framework\App\Config\ScopeConfigInterface::class);

        foreach ($indexData as $product_id => $indexDataItem) {
            $hrefLangs = [];
            if ($indexData[$product_id]['type_id'] == 'simple' || !isset($indexData[$product_id]['clone_of'])) {
                continue;
            }

            foreach($stores as $store){
                try {
                    $product = $this->productRepositoryInterface->get($indexData[$product_id]['clone_of'], false, $store->getId());
                    $category = null;

                    /* @TODO: once approved, move out of this loop */
                    if (!isset($this->storeLocales[$store->getId()])) {
                        $website = $websiteManager->load($store->getWebsiteId());
                        $locale = $configReader->getValue('general/locale/code', 'website', $website->getCode());
                        $this->storeLocales[$store->getId()] = $locale;
                    }
                    $hrefLangs[str_replace('_', '-', $this->storeLocales[$store->getId()])] = $product->getUrlKey();
                } catch (\Exception $e){

                }
            }

            $indexData[$product_id]['storecode_url_paths'] = $hrefLangs;
        }
        return $indexData;
    }

    private function addDiscountAmount($indexData, $storeId)
    {
        foreach ($indexData as $product_id => $indexDataItem) {
            $productTypeID = $indexData[$product_id]['type_id'];
            if ($productTypeID != 'configurable') {
                continue;
            }

            // $configurableDiscountAmount = null;
            // if (isset($indexDataItem['final_price']) && isset($indexDataItem['regular_price'])) {
            //     $configurableFinalPrice = $indexDataItem['final_price'];
            //     $configurableRegularPrice = $indexDataItem['regular_price'];
            //     if ($configurableFinalPrice && $configurableRegularPrice) {
            //         $configurableDiscountAmount = intval(round(100 - (($configurableFinalPrice / $configurableRegularPrice) * 100)));
            //     }
            // }
            // $indexData[$product_id]['discount_amount'] = $configurableDiscountAmount;
            if (array_key_exists('configurable_children', $indexDataItem) && is_iterable($indexDataItem['configurable_children'])) {
                foreach ($indexDataItem['configurable_children'] as $key => $child) {
                    /**
                     * Preparing configurable product ids
                     */
                    $this->loadedConfigurableIds[] = $child['id'];
                    $childDiscountAmount = null;
                    if (isset($indexDataItem['is_clone']) && $indexDataItem['is_clone'] == 1 && $child['color'] != $indexDataItem['clone_color_id']) {
                        continue;
                    }
                    if (isset($child['final_price']) && isset($child['regular_price'])) {
                        $childFinalPrice = $child['final_price'];
                        $childRegularPrice = $child['regular_price'];
                        if ($childFinalPrice && $childRegularPrice) {
                            $childDiscountAmount = intval(round(100 - (($childFinalPrice / $childRegularPrice) * 100)));
                        }
                    }

                    $indexData[$product_id]['configurable_children'][$key]['discount_amount'] = $childDiscountAmount;
                }
            }
        }
        return $indexData;
    }

    private function replaceOriginalChild($parentIndexData,$newChildData){
        foreach($parentIndexData['configurable_children'] as $childKey =>$childData){
            if($childData['sku'] == $newChildData['sku']) {
                $parentIndexData['configurable_children'][$childKey] = $newChildData;
            }
        }

        return $parentIndexData;
    }

    /**
     * This method will delete child products from ES which are disabled from magento.
     *
     * @param array $indexDataItem
     * @param int $storeId
     *
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function invalidateDisabledChildProducts($indexDataItem, $storeId)
    {
        $configurableProductId = $indexDataItem['entity_id'];
        $configurableProduct = $this->productRepositoryInterface->getById(
            $configurableProductId,
            false,
            $storeId
        );
        $children = $configurableProduct->getTypeInstance()->getUsedProducts($configurableProduct);
        $childData = [];
        foreach ($children as $child) {
            $childData[$child->getId()] = $configurableProductId . '-' . $child->getColor() . '-' . $child->getSize();
        }
        $indexDataChildIds = [];
        $indexDataChildIds = array_column($indexDataItem['configurable_children'], 'id');
        $disabledProductIds = array_diff(array_keys($childData), $indexDataChildIds);
        if ($disabledProductIds) {
            $childData = array_flip($childData);
            $cloneIds = array_intersect($childData, $disabledProductIds);
            $cloneIds = array_keys($cloneIds);
            if ($cloneIds) {
                //Delete disabled products from ES and clean cache
                $store = $this->storeManager->getStore($storeId);
                $this->indexerHandler->cleanUpByTransactionKey($store, $cloneIds);
                $this->cacheProcessor->cleanCacheByDocIds($storeId, 'product', [$configurableProductId]);
            }
        }
    }
}

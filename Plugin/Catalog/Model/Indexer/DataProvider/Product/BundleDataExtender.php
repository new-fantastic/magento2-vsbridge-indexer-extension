<?php

namespace CodingMice\VsBridgeIndexerExtension\Plugin\Catalog\Model\Indexer\DataProvider\Product;

use CodingMice\VsBridgeIndexerExtension\Model\AdditionalData\CategoryNames;
use Divante\VsbridgeIndexerCatalog\Model\Indexer\DataProvider\Product\BundleOptionsData;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ObjectManager;

class BundleDataExtender
{
    /**
     * Loaded bundle product ids
     *
     * @var array
     */
    protected $loadedBundleIds = [];

    /**
     * Prepare category name
     *
     * @var CategoryNames
     */
    protected $categoryNames;

    /**
     * BundleDataExtender constructor.
     *
     * @param CategoryNames $categoryNames
     */
    public function __construct(CategoryNames $categoryNames)
    {
        $this->categoryNames = $categoryNames;
    }

    public function afterAddData(BundleOptionsData $subject, $result, $indexData, $storeId)
    {
        $result = $this->addDiscountAmount($result, $storeId);

        $result = $this->categoryNames->prepareAditionalIndexerData($this->loadedBundleIds, $result, $storeId, 'bundle', true);

        $result = $this->slugWithCollection($result, $storeId);

        return $result;
    }

    private function addDiscountAmount($indexData, $storeId)
    {
        $objectManager = ObjectManager::getInstance();
        $productRepository = $objectManager->create(ProductRepositoryInterface::class);

        foreach ($indexData as $product_id => $indexDataItem) {
            if ($indexData[$product_id]['type_id'] != 'bundle') {
                continue;
            }

            /**
             * Preparing bundle product ids
             */
            $this->loadedBundleIds[] = $product_id;

            // It is not really a clone. However, it will make me so easier to search in PWA
            $indexData[$product_id]['is_clone'] = 3;
            $indexData[$product_id]['clone_name'] = $indexData[$product_id]['name'];

            // Add slug_from_name for pretty URLs
            // I did same for Configurables
            // These functions are just equal `slugify`
            // $indexDataItem['slug_from_name'] = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $indexDataItem['name'])));
            $indexData[$product_id]['slug_from_name'] = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $indexDataItem['name'])));

            $bundlePrice = $indexDataItem['price'] ?? null;

            if (!array_key_exists('bundle_options', $indexDataItem)) {
                continue;
            }

            $childrenRegularPrize = [
                'min' => 0,
                'max' => 0
            ];
            foreach ($indexDataItem['bundle_options'] as $bundleOption) {
                $optionPrizes = [];
                if(empty($bundleOption['product_links'])){
                    continue;
                }
                foreach ($bundleOption['product_links'] as $child) {
                    $childSKU = $child['sku'];
                    $childProduct = $productRepository->get($childSKU, false, $storeId);
                    if (isset($childProduct['size'])) {
                        if (!isset($indexData[$product_id]['size_in_color_options'])) {
                            $indexData[$product_id]['size_in_color_options'] = [];
                        }
                        $indexData[$product_id]['size_in_color_options'][] = intval($childProduct['size']);
                    }
                    $childRegularPrize = $childProduct->getPriceInfo()->getPrice('regular_price')->getAmount()->getValue();
                    $optionPrizes[] = intval(round($childRegularPrize));
                }
                sort($optionPrizes);
                $childrenRegularPrize['min'] += $optionPrizes[0];
                $childrenRegularPrize['max'] += end($optionPrizes);
            }

            if (isset($indexData[$product_id]['size_in_color_options'])) {
                $indexData[$product_id]['size_in_color_options'] = array_values(array_unique($indexData[$product_id]['size_in_color_options']));
            }

            $indexData[$product_id]['bundle_children_price'] = $childrenRegularPrize['min'] . '-' . $childrenRegularPrize['max'];

            $discountAmount = null;
            if ($childrenRegularPrize['min'] == $childrenRegularPrize['max']) {
                $childrenRegularPrize = $childrenRegularPrize['max'];
                $discountAmount = 0;
                if($childrenRegularPrize > 0) {
                    $discountAmount = intval(round(100 - (($bundlePrice / $childrenRegularPrize) * 100)));
                }
            } else {
                $discountAmountMin = 0;
                if($childrenRegularPrize['min'] > 0) {
                    $discountAmountMin = intval(round(100 - (($bundlePrice / $childrenRegularPrize['min']) * 100)));
                }

                $discountAmountMax = 0;
                if($childrenRegularPrize['max'] > 0) {
                    $discountAmountMax = intval(round(100 - (($bundlePrice / $childrenRegularPrize['max']) * 100)));
                }
                /**
                 * Frontend team confirmed, that they are not using this discount attribute in PWA.
                 * So as a temp fix, I am making the discount amount an integer value always.
                 */
                $discountAmount = $discountAmountMin - $discountAmountMax;
            }

            $indexData[$product_id]['discount'] = $discountAmount;
        }
        return $indexData;
    }

    private function slugWithCollection($indexData, $storeId)
    {
        foreach ($indexData as $product_id => $indexDataItem) {
            if ($indexData[$product_id]['type_id'] != 'bundle') {
                continue;
            }

            $indexData[$product_id]['clone_of'] = $indexData[$product_id]['sku'];
            // Add slug_from_name for pretty URLs
            // I did same for Configurables
            // These functions are just equal `slugify`
            // $indexDataItem['slug_from_name'] = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $indexDataItem['name'])));
            $collection = isset($indexData[$product_id]['collections_names'][0]) ? $indexData[$product_id]['collections_names'][0] : '';
            $indexData[$product_id]['collection_name'] = $collection;
            if (strlen($collection) > 0) {
                $collection .= ' ';
            }
            $indexData[$product_id]['slug_from_name'] = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $collection.$indexDataItem['name'])));

           
        }
        return $indexData;
    }
}

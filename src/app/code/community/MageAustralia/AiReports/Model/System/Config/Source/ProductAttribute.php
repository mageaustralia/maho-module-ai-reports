<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Model_System_Config_Source_ProductAttribute
{
    /**
     * Product attributes selectable as the "brand" dimension. Limited to the
     * input types that make sense to group by (select/multiselect/text).
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [['value' => '', 'label' => Mage::helper('aireports')->__('-- Auto-detect --')]];

        /** @var Mage_Catalog_Model_Resource_Product_Attribute_Collection $collection */
        $collection = Mage::getResourceModel('catalog/product_attribute_collection');
        $collection->addFieldToFilter('frontend_input', ['in' => ['select', 'multiselect', 'text']])
            ->setOrder('attribute_code', Varien_Data_Collection::SORT_ORDER_ASC);

        /** @var Mage_Catalog_Model_Resource_Eav_Attribute $attribute */
        foreach ($collection as $attribute) {
            $code  = (string) $attribute->getAttributeCode();
            $label = (string) ($attribute->getFrontendLabel() ?: $code);
            $options[] = ['value' => $code, 'label' => "{$label} ({$code})"];
        }

        return $options;
    }
}

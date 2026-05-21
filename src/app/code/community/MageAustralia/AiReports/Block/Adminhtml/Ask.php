<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Block_Adminhtml_Ask extends Mage_Adminhtml_Block_Template
{
    public function getGenerateUrl(): string
    {
        return $this->getUrl('adminhtml/aireports/generate');
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('adminhtml/aireports/save');
    }

    public function getExportUrl(): string
    {
        return $this->getUrl('adminhtml/aireports/exportCsv');
    }

    public function getDrillUrl(): string
    {
        return $this->getUrl('adminhtml/aireports/drill');
    }

    /** @return array<int, array{label:string, prompt:string}> */
    public function getSuggestions(): array
    {
        return [
            ['label' => 'Top sellers this month', 'prompt' => 'What are the top 20 selling products this month?'],
            ['label' => 'Stock vs sales', 'prompt' => 'For our top 50 sellers over the last 90 days, show stock on hand and days of cover.'],
            ['label' => 'Growth last 6 months', 'prompt' => 'Which products had the biggest growth comparing the last 3 months to the previous 3 months?'],
            ['label' => 'Low stock alerts', 'prompt' => 'Which products have less than 14 days of stock cover based on the last 30 days of sales?'],
            ['label' => 'Revenue by store', 'prompt' => 'Break down revenue by store for last month.'],
            ['label' => 'Daily revenue', 'prompt' => 'Show daily revenue for the last 30 days.'],
            ['label' => 'Revenue by brand', 'prompt' => 'Show revenue by brand for last month.'],
            ['label' => 'Sales by category', 'prompt' => 'Break down sales by category for last month.'],
            ['label' => 'Revenue by payment method', 'prompt' => 'Break down revenue by payment method for last month.'],
            ['label' => 'Sales by state', 'prompt' => 'Show revenue by state for last month.'],
            ['label' => 'Revenue by country', 'prompt' => 'Break down revenue by country for last month.'],
            ['label' => 'Top coupon codes', 'prompt' => 'What are the top coupon codes by revenue last month?'],
            ['label' => 'Revenue by shipping method', 'prompt' => 'Break down revenue by shipping method for last month.'],
            ['label' => 'Discounts given', 'prompt' => 'How much did we give away in discounts last month, by coupon code?'],
            ['label' => 'Tax collected', 'prompt' => 'How much tax did we collect last month?'],
            ['label' => 'Shipping charged', 'prompt' => 'How much shipping did we charge last month?'],
        ];
    }
}

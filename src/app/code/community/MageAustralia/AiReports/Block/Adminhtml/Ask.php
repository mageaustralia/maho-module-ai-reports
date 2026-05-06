<?php

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

    /** @return array<int, array{label:string, prompt:string}> */
    public function getSuggestions(): array
    {
        return [
            ['label' => 'Top sellers this month', 'prompt' => 'What are the top 20 selling products this month?'],
            ['label' => 'Stock vs sales', 'prompt' => 'For our top 50 sellers over the last 90 days, show stock on hand and days of cover.'],
            ['label' => 'Growth last 6 months', 'prompt' => 'Which products had the biggest growth comparing the last 3 months to the previous 3 months?'],
            ['label' => 'Low stock alerts', 'prompt' => 'Which products have less than 14 days of stock cover based on the last 30 days of sales?'],
            ['label' => 'Revenue by category', 'prompt' => 'Break down revenue by category for last month.'],
            ['label' => 'Daily revenue', 'prompt' => 'Show daily revenue for the last 30 days.'],
        ];
    }
}

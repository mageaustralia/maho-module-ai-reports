<?php

declare(strict_types=1);

trait MageAustralia_AiReports_Model_Primitive_UrlBuilderTrait
{
    /**
     * Build an admin URL via Mage::helper('adminhtml') when Mage is available,
     * or fall back to a plain path for unit-test contexts where Mage is not bootstrapped.
     *
     * @param array<string, mixed> $params
     */
    private function buildAdminUrl(string $route, array $params): string
    {
        if (class_exists('Mage', false) && Mage::isInstalled()) {
            return Mage::helper('adminhtml')->getUrl($route, $params);
        }
        // Fallback for unit-test contexts where Mage is not bootstrapped.
        $segments = '';
        foreach ($params as $k => $v) {
            $segments .= "/$k/$v";
        }
        return '/admin/' . str_replace('adminhtml/', '', $route) . $segments;
    }
}

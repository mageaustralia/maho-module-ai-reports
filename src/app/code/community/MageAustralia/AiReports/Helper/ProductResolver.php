<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Helper_ProductResolver extends Mage_Core_Helper_Abstract
{
    public const DEFAULT_TOP_K = 5;
    public const DEFAULT_THRESHOLD = 0.55;
    public const APCU_TTL = 300; // 5 minutes

    /**
     * Embed the question and return the top-K most similar catalog products.
     *
     * @return array<int, array{id: int, name: string, similarity: float}>
     */
    public function resolve(
        string $question,
        int $storeId = 0,
        int $topK = self::DEFAULT_TOP_K,
        float $threshold = self::DEFAULT_THRESHOLD,
    ): array {
        $question = trim($question);
        if ($question === '') {
            return [];
        }

        // Embed the question
        try {
            $vectors = Mage::helper('ai')->embed(
                text: $question,
                storeId: $storeId,
                consumer: 'aireports_resolver',
            );
        } catch (\Throwable $e) {
            Mage::log(
                'AiReports ProductResolver embed error: ' . $e->getMessage(),
                Mage::LOG_INFO,
                'aireports.log',
            );
            return [];
        }
        $questionVector = $vectors[0] ?? null;
        if (!is_array($questionVector) || empty($questionVector)) {
            return [];
        }

        // Load product vectors for this store
        $candidates = $this->getProductVectors($storeId);
        if (empty($candidates)) {
            return [];
        }

        // Score by cosine similarity
        $scored = [];
        $qNorm  = $this->norm($questionVector);
        if ($qNorm <= 0.0) {
            return [];
        }
        foreach ($candidates as $entityId => $row) {
            $similarity = $this->cosineSimilarity($questionVector, $row['vector'], $qNorm, $row['norm']);
            if ($similarity >= $threshold) {
                $scored[] = ['id' => $entityId, 'similarity' => $similarity];
            }
        }
        if (empty($scored)) {
            return [];
        }

        usort($scored, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);
        $scored = array_slice($scored, 0, $topK);

        // Look up names
        $ids   = array_map(fn ($r) => $r['id'], $scored);
        $names = $this->loadProductNames($ids, $storeId);

        $result = [];
        foreach ($scored as $r) {
            $result[] = [
                'id'         => (int) $r['id'],
                'name'       => $names[(int) $r['id']] ?? ('Product ' . $r['id']),
                'similarity' => round($r['similarity'], 4),
            ];
        }
        return $result;
    }

    /**
     * Load all catalog_product vectors for the given store from maho_ai_vector.
     * Prefers store-specific rows; falls back to store_id=0 for entities not in that store.
     *
     * @return array<int, array{vector: float[], norm: float}>
     */
    private function getProductVectors(int $storeId): array
    {
        $cacheKey = "aireports_product_vectors_store_{$storeId}";
        if (function_exists('apcu_fetch')) {
            $hit    = false;
            $cached = apcu_fetch($cacheKey, $hit);
            if ($hit && is_array($cached)) {
                return $cached;
            }
        }

        $conn   = Mage::getSingleton('core/resource')->getConnection('core_read');
        $table  = Mage::getSingleton('core/resource')->getTableName('ai/vector');
        $select = $conn->select()
            ->from($table, ['entity_id', 'store_id', 'vector'])
            ->where('entity_type = ?', 'catalog_product')
            ->where('store_id IN (?)', [$storeId, 0])
            ->order('store_id DESC'); // prefer storeId over 0

        $rows     = $conn->fetchAll($select);
        $byEntity = [];
        foreach ($rows as $row) {
            $entityId = (int) $row['entity_id'];
            // First encounter wins because of ORDER BY store_id DESC (storeId-specific row comes first)
            if (isset($byEntity[$entityId])) {
                continue;
            }
            $vec = json_decode($row['vector'], true);
            if (!is_array($vec) || empty($vec)) {
                continue;
            }
            $byEntity[$entityId] = ['vector' => $vec, 'norm' => $this->norm($vec)];
        }

        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $byEntity, self::APCU_TTL);
        }
        return $byEntity;
    }

    /**
     * Load product names via catalog collection.
     *
     * @param int[] $ids
     * @return array<int, string>
     */
    private function loadProductNames(array $ids, int $storeId): array
    {
        if (empty($ids)) {
            return [];
        }
        /** @var Mage_Catalog_Model_Resource_Product_Collection $collection */
        $collection = Mage::getResourceModel('catalog/product_collection');
        $collection->addFieldToFilter('entity_id', ['in' => $ids]);
        $collection->addAttributeToSelect('name');
        if ($storeId > 0) {
            $collection->setStoreId($storeId);
        }
        $names = [];
        foreach ($collection as $product) {
            $names[(int) $product->getId()] = (string) $product->getName();
        }
        return $names;
    }

    /**
     * Euclidean norm of a float vector.
     *
     * @param float[] $v
     */
    public function norm(array $v): float
    {
        $sum = 0.0;
        foreach ($v as $x) {
            $sum += $x * $x;
        }
        return sqrt($sum);
    }

    /**
     * Cosine similarity between two vectors (pre-computed norms for efficiency).
     *
     * @param float[] $a
     * @param float[] $b
     */
    public function cosineSimilarity(array $a, array $b, float $aNorm, float $bNorm): float
    {
        if ($aNorm <= 0.0 || $bNorm <= 0.0) {
            return 0.0;
        }
        $count = min(count($a), count($b));
        $dot   = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $dot += $a[$i] * $b[$i];
        }
        return $dot / ($aNorm * $bNorm);
    }
}

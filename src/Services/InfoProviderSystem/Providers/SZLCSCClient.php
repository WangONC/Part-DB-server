<?php
/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2023 Jan Böhmer (https://github.com/jbtronics)
 *  Copyright (C) 2024 Nexrem (https://github.com/meganukebmp)
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as published
 *  by the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace App\Services\InfoProviderSystem\Providers;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class SZLCSCClient
{
    private const SEARCH_HOST = 'https://so.szlcsc.com';
    private const ITEM_HOST = 'https://item.szlcsc.com';
    private const LIST_HOST = 'https://list.szlcsc.com';

    // iPhone 12 / iOS 18.1.1
    private const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Html5Plus/1.0 (Immersed/20) uni-app';

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    private function getDefaultHeaders(): array
    {
        return [
            'Accept' => '*/*',
            'X-Lc-Source' => 'Ios',
            'Accept-Language' => 'zh-CN,zh-Hans;q=0.9',
            'Origin' => 'https://m.szlcsc.com',
            'Referer' => 'https://m.szlcsc.com/',
            'Sec-Fetch-Site' => 'same-site',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Dest' => 'empty',
            'User-Agent' => self::UA,
            'X-Lc-Accesssharecode' => '',
        ];
    }

    private function requestJson(string $method, string $url, array $options = []): array
    {
        $options['headers'] = array_merge(
            $this->getDefaultHeaders(),
            $options['headers'] ?? []
        );

        $response = $this->httpClient->request($method, $url, $options);
        $content = $response->getContent(false);

        $data = json_decode($content, true);

        if (!is_array($data)) {
            throw new \RuntimeException('SZLCSC returned invalid JSON.');
        }

        if (($data['code'] ?? null) !== 200) {
            throw new \RuntimeException('SZLCSC request failed: ' . ($data['msg'] ?? 'unknown error'));
        }

        return $data['result'] ?? [];
    }

    public function searchProducts(
        string $keyword,
        int $page = 1,
        int $pageSize = 10,
        int $sort = 0,
        bool $stock = false
    ): array {
        return $this->requestJson('GET', self::SEARCH_HOST . '/phone/p/product/search', [
            'query' => [
                'keyword' => $keyword,
                'currPage' => $page,
                'pageSize' => $pageSize,
                'sort' => $sort,
                'stock' => $stock ? 1 : 0,
            ],
        ]);
    }

    public function getProductDetail(string $productSignId): array
    {
        return $this->requestJson('GET', self::ITEM_HOST . '/phone/p/' . $productSignId);
    }

    public function getProductList(
        int $catalog,
        string $keyword = '',
        int $page = 1,
        int $pageSize = 10,
        int $sort = 0,
        bool $stock = false
    ): array {
        return $this->requestJson('GET', self::LIST_HOST . '/phone/p/product/list', [
            'query' => [
                'catalog' => $catalog,
                'keyword' => $keyword,
                'currPage' => $page,
                'pageSize' => $pageSize,
                'sort' => $sort,
                'stock' => $stock ? 1 : 0,
            ],
        ]);
    }

    private function findExactProductByCode(array $products, string $cCode): ?array
    {
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }

            if (($product['code'] ?? null) === $cCode) {
                return $product;
            }
        }

        return null;
    }

    public function getProductInfoByCCode(string $cCode): ?array
    {
        $cCode = trim($cCode);
        if ($cCode === '') {
            return null;
        }

        $search = $this->searchProducts($cCode, 1, 10);
        $searchProducts = $search['productList'] ?? [];

        if (!is_array($searchProducts) || $searchProducts === []) {
            return null;
        }

        $exactMatch = $this->findExactProductByCode($searchProducts, $cCode);
        if ($exactMatch === null) {
            return null;
        }

        // basic 优先尝试用分类商品列表结果覆盖，因为它通常更完整
        $basic = $exactMatch;

        $catalogId = $exactMatch['catalogId'] ?? null;
        if ($catalogId !== null && $catalogId !== '') {
            try {
                $catalogResult = $this->getProductList((int) $catalogId, $cCode, 1, 10);
                $catalogProducts = $catalogResult['productList'] ?? [];

                if (is_array($catalogProducts)) {
                    $catalogMatch = $this->findExactProductByCode($catalogProducts, $cCode);
                    if ($catalogMatch !== null) {
                        $basic = $catalogMatch;
                    }
                }
            } catch (\Throwable) {
                // 分类列表失败时，退回搜索结果
            }
        }

        // detail 仍然依赖 productSignId
        $productSignId = $basic['productSignId'] ?? $exactMatch['productSignId'] ?? null;
        $detail = [];

        if (is_string($productSignId) && trim($productSignId) !== '') {
            $detail = $this->getProductDetail($productSignId);
        }

        return [
            'basic' => $basic,
            'detail' => $detail,
        ];
    }

    public function getCProductInfoByKeyword(string $keyword): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }

        $search = $this->searchProducts($keyword, 1, 10);
        $productList = $search['productList'] ?? [];

        if (!is_array($productList) || $productList === []) {
            return [];
        }

        $results = [];

        foreach ($productList as $product) {
            if (!is_array($product)) {
                continue;
            }

            $detail = [];
            $productSignId = $product['productSignId'] ?? null;

            if (is_string($productSignId) && trim($productSignId) !== '') {
                try {
                    $detail = $this->getProductDetail($productSignId);
                } catch (\Throwable) {
                    $detail = [];
                }
            }

            $results[] = [
                'basic' => $product,
                'detail' => $detail,
            ];
        }

        return $results;
    }

    public function getDetailImages(array $detail): array
    {
        $image = $detail['image'] ?? null;

        if (!is_string($image) || trim($image) === '') {
            return [];
        }

        $parts = explode('<$>', $image);

        return array_values(array_filter(array_map(
            static fn(string $item) => trim($item),
            $parts
        ), static fn(string $item) => $item !== ''));
    }

    public function getCProductInfoByKeywordsBatch(array $keywords): array
    {
        if (empty($keywords)) {
            return [];
        }

        $searchResponses = [];
        $results = [];

        foreach ($keywords as $keyword) {
            $searchResponses[$keyword] = $this->httpClient->request(
                'GET',
                self::SEARCH_HOST . '/phone/p/product/search',
                [
                    'headers' => $this->getDefaultHeaders(),
                    'query' => [
                        'keyword' => $keyword,
                        'currPage' => 1,
                        'pageSize' => 10,
                        'sort' => 0,
                        'stock' => 0,
                    ],
                ]
            );
        }

        $detailResponses = [];
        $detailMap = [];

        foreach ($searchResponses as $keyword => $response) {
            try {
                $content = $response->getContent(false);
                $data = json_decode($content, true);

                if (!is_array($data) || ($data['code'] ?? null) !== 200) {
                    $results[$keyword] = [];
                    continue;
                }

                $productList = $data['result']['productList'] ?? [];
                if (!is_array($productList) || $productList === []) {
                    $results[$keyword] = [];
                    continue;
                }

                $results[$keyword] = [];

                foreach ($productList as $index => $product) {
                    if (!is_array($product)) {
                        continue;
                    }

                    $productSignId = $product['productSignId'] ?? null;

                    // 先把 basic 塞进去
                    $detailMap[$keyword][$index] = [
                        'basic' => $product,
                        'detail' => [],
                    ];

                    // 有 productSignId 才发详情请求
                    if (is_string($productSignId) && trim($productSignId) !== '') {
                        $detailResponses[$keyword . '::' . $index] = $this->httpClient->request(
                            'GET',
                            self::ITEM_HOST . '/phone/p/' . $productSignId,
                            [
                                'headers' => $this->getDefaultHeaders(),
                            ]
                        );
                    }
                }
            } catch (\Throwable) {
                $results[$keyword] = [];
            }
        }

        foreach ($detailResponses as $key => $response) {
            [$keyword, $index] = explode('::', $key, 2);

            try {
                $content = $response->getContent(false);
                $data = json_decode($content, true);

                if (is_array($data) && ($data['code'] ?? null) === 200) {
                    $detailMap[$keyword][(int) $index]['detail'] = $data['result'] ?? [];
                }
            } catch (\Throwable) {
                // detail 保持空数组即可
            }
        }

        foreach ($keywords as $keyword) {
            if (!isset($detailMap[$keyword])) {
                $results[$keyword] = $results[$keyword] ?? [];
                continue;
            }

            $results[$keyword] = array_values($detailMap[$keyword]);
        }

        return $results;
    }
}
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
    private ?array $catalogPathMap = null;

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

    public function getBrandCatalogData(): array
    {
        return $this->requestJson('GET', self::SEARCH_HOST . '/phone/p/catalog/brand/list');
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

    public function getProductInfoByCCode(string $cCode, bool $withPrice = true, bool $withDetail = true): ?array
    {
        $cCode = trim($cCode);
        if ($cCode === '') {
            return null;
        }

        $products = $this->getProductInfoByKeyword($cCode, $withPrice, $withDetail);

        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }

            $basic = $product['basic'] ?? null;
            if (is_array($basic) && ($basic['code'] ?? null) === $cCode) {
                return $product;
            }
        }

        return null;
    }


    public function getProductInfoByKeyword(string $keyword, bool $withPrice = false, bool $withDetail = false): array
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

        $products = $productList;

        if ($withPrice) {
            $products = array_map(function ($product) use ($keyword) {
                if (!is_array($product)) {
                    return $product;
                }

                $catalogId = $product['catalogId'] ?? null;
                if ($catalogId === null || $catalogId === '') {
                    return $product;
                }

                try {
                    $catalogResult = $this->getProductList((int) $catalogId, $keyword, 1, 10);
                    $catalogProducts = $catalogResult['productList'] ?? [];

                    if (is_array($catalogProducts)) {
                        $catalogMatch = $this->findExactProductByCode($catalogProducts, $product['code'] ?? '');
                        if ($catalogMatch !== null) {
                            return $catalogMatch;
                        }
                    }
                } catch (\Throwable) {
                    // 分类列表失败时，退回原始 basic
                }

                return $product;
            }, $products);
        }

        $results = [];

        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }

            $detail = [];
            $productSignId = $product['productSignId'] ?? null;

            if ($withDetail && is_string($productSignId) && trim($productSignId) !== '') {
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

    public function getProductInfoByKeywordsBatch(array $keywords): array
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

    public function getCatalogPathById(int $catalogId): ?string
    {
        if ($this->catalogPathMap === null) {
            $this->catalogPathMap = [];

            $data = $this->getBrandCatalogData();
            $catalogList = $data['catalogList'] ?? [];

            $buildMap = function (array $nodes, ?string $parentName = null) use (&$buildMap): void {
                foreach ($nodes as $node) {
                    if (!is_array($node)) {
                        continue;
                    }

                    $id = isset($node['catalogId']) ? (int) $node['catalogId'] : null;
                    $name = isset($node['catalogName']) ? trim((string) $node['catalogName']) : '';

                    if ($id !== null && $name !== '') {
                        $this->catalogPathMap[$id] = $parentName !== null && $parentName !== ''
                            ? $parentName . ' -> ' . $name
                            : $name;
                    }

                    $sons = $node['sonList'] ?? [];
                    if (is_array($sons) && $sons !== []) {
                        $buildMap($sons, $name !== '' ? $name : $parentName);
                    }
                }
            };

            if (is_array($catalogList)) {
                $buildMap($catalogList);
            }
        }

        return $this->catalogPathMap[$catalogId] ?? null;
    }

    public function getProductPdfAndPdb(string $cCode, string $annexNumber): array
    {
        return $this->requestJson('GET', self::SEARCH_HOST . '/product/showProductPDFAndPCB', [
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0',
                'Referer' => 'https://www.szlcsc.com/',
                'Origin' => 'https://www.szlcsc.com',
                'X-Lc-Source' => 'Web',
            ],
            'query' => [
                'productCode' => $cCode,
                'annexNumber' => $annexNumber,
            ],
        ]);
    }

    public function getProductAnnexList(string $cCode, string $annexNumber): array
    {
        $result = $this->getProductPdfAndPdb($cCode, $annexNumber);

        $annexes = [];
        $baseDomain = 'https://atta.szlcsc.com/';

        $fileTypeVOList = $result['fileTypeVOList'] ?? [];
        if (is_array($fileTypeVOList)) {
            foreach ($fileTypeVOList as $group) {
                if (!is_array($group)) {
                    continue;
                }

                $type = isset($group['fileType']) && is_string($group['fileType'])
                    ? $group['fileType']
                    : null;

                $detailVOList = $group['detailVOList'] ?? [];
                if (!is_array($detailVOList)) {
                    continue;
                }

                foreach ($detailVOList as $detail) {
                    if (!is_array($detail)) {
                        continue;
                    }

                    $url = null;

                    if (!empty($detail['linkAddress']) && is_string($detail['linkAddress'])) {
                        $url = $detail['linkAddress'];
                    } elseif (!empty($detail['fileUrl']) && is_string($detail['fileUrl'])) {
                        $url = $baseDomain . ltrim($detail['fileUrl'], '/') . (string) ($detail['urlSign'] ?? '');
                    }

                    if ($url !== null) {
                        $annexes[] = [
                            'url' => $url,
                            'name' => isset($detail['fileName']) && is_string($detail['fileName'])
                                ? $detail['fileName']
                                : null,
                            'type' => $type,
                        ];
                    }
                }
            }
        }

        $fileList = $result['fileList'] ?? [];
        if (is_array($fileList)) {
            foreach ($fileList as $file) {
                if (!is_array($file)) {
                    continue;
                }

                if (!empty($file['annexUrl']) && is_string($file['annexUrl'])) {
                    $url = $baseDomain . ltrim($file['annexUrl'], '/') . (string) ($file['urlSign'] ?? '');

                    $annexes[] = [
                        'url' => $url,
                        'name' => isset($file['annexRealName']) && is_string($file['annexRealName'])
                            ? $file['annexRealName']
                            : null,
                        'type' => null,
                    ];
                }
            }
        }

        $pdfLink = $result['pdfLink'] ?? null;
        if (is_string($pdfLink) && trim($pdfLink) !== '') {
            $exists = false;
            foreach ($annexes as $annex) {
                if (($annex['url'] ?? null) === $pdfLink) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {
                array_unshift($annexes, [
                    'url' => $pdfLink,
                    'name' => isset($result['productCode']) && is_string($result['productCode'])
                        ? $result['productCode']
                        : null,
                    'type' => null,
                ]);
            }
        }
        return $annexes;
    }
}

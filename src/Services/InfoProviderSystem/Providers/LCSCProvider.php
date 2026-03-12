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

use App\Services\InfoProviderSystem\DTOs\FileDTO;
use App\Services\InfoProviderSystem\DTOs\ParameterDTO;
use App\Services\InfoProviderSystem\DTOs\PartDetailDTO;
use App\Services\InfoProviderSystem\DTOs\PriceDTO;
use App\Services\InfoProviderSystem\DTOs\PurchaseInfoDTO;
use App\Settings\InfoProviderSystem\LCSCSettings;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class LCSCProvider implements BatchInfoProviderInterface, URLHandlerInfoProviderInterface
{

    private const ENDPOINT_URL = 'https://wmsc.lcsc.com/ftps/wm';

    public const DISTRIBUTOR_NAME = 'LCSC';

    public function __construct(private readonly HttpClientInterface $lcscClient, private readonly LCSCSettings $settings, private readonly SZLCSCClient $szlcscClient) {}

    public function getProviderInfo(): array
    {
        return [
            'name' => 'LCSC',
            'description' => 'This provider uses the (unofficial) LCSC API to search for parts.',
            'url' => 'https://www.lcsc.com/',
            'disabled_help' => 'Enable this provider in the provider settings.',
            'settings_class' => LCSCSettings::class,
        ];
    }

    public function getProviderKey(): string
    {
        return 'lcsc';
    }

    // This provider is always active
    public function isActive(): bool
    {
        return $this->settings->enabled;
    }

    /**
     * @param  string  $id
     * @param  bool  $lightweight If true, skip expensive operations like datasheet resolution
     * @return PartDetailDTO
     */
    private function queryDetailIntl(string $id, bool $lightweight = false): PartDetailDTO
    {
        $response = $this->lcscClient->request('GET', self::ENDPOINT_URL . "/product/detail", [
            'headers' => [
                'Cookie' => new Cookie('currencyCode', $this->settings->currency)
            ],
            'query' => [
                'productCode' => $id,
            ],
        ]);

        $arr = $response->toArray();
        $product = $arr['result'] ?? null;

        if ($product === null) {
            throw new \RuntimeException('Could not find product code: ' . $id);
        }

        return $this->getPartDetailIntl($product, $lightweight);
    }

    /**
     * @param  string  $url
     * @return String
     */
    private function getRealDatasheetUrl(?string $url): string
    {
        if ($url !== null && trim($url) !== '' && preg_match("/^https:\/\/(datasheet\.lcsc\.com|www\.lcsc\.com\/datasheet)\/.*(C\d+)\.pdf$/", $url, $matches) > 0) {
            if (preg_match("/^https:\/\/datasheet\.lcsc\.com\/lcsc\/(.*\.pdf)$/", $url, $rewriteMatches) > 0) {
                $url = 'https://www.lcsc.com/datasheet/lcsc_datasheet_' . $rewriteMatches[1];
            }
            $response = $this->lcscClient->request('GET', $url, [
                'headers' => [
                    'Referer' => 'https://www.lcsc.com/product-detail/_' . $matches[2] . '.html'
                ],
            ]);
            if (preg_match('/(previewPdfUrl): ?("[^"]+wmsc\.lcsc\.com[^"]+\.pdf")/', $response->getContent(), $matches) > 0) {
                //HACKY: The URL string contains escaped characters like \u002F, etc. To decode it, the JSON decoding is reused
                //See https://github.com/Part-DB/Part-DB-server/pull/582#issuecomment-2033125934
                $jsonObj = json_decode('{"' . $matches[1] . '": ' . $matches[2] . '}');
                $url = $jsonObj->previewPdfUrl;
            }
        }
        return $url;
    }

    /**
     * @param  string  $term
     * @param  bool  $lightweight If true, skip expensive operations like datasheet resolution
     * @return PartDetailDTO[]
     */
    private function queryByTermIntl(string $term, bool $lightweight = false): array
    {
        // Optimize: If term looks like an LCSC part number (starts with C followed by digits),
        // use direct detail query instead of slower search
        if (preg_match('/^C\d+$/i', trim($term))) {
            try {
                return [$this->queryDetailIntl(trim($term), $lightweight)];
            } catch (\Exception $e) {
                // If direct lookup fails, fall back to search
                // This handles cases where the C-code might not exist
            }
        }

        $response = $this->lcscClient->request('POST', self::ENDPOINT_URL . "/search/v2/global", [
            'headers' => [
                'Cookie' => new Cookie('currencyCode', $this->settings->currency)
            ],
            'json' => [
                'keyword' => $term,
            ],
        ]);

        $arr = $response->toArray();

        // Get products list
        $products = $arr['result']['productSearchResultVO']['productList'] ?? [];
        // Get product tip
        $tipProductCode = $arr['result']['tipProductDetailUrlVO']['productCode'] ?? null;

        $result = [];

        // LCSC does not display LCSC codes in the search, instead taking you directly to the
        // detailed product listing. It does so utilizing a product tip field.
        // If product tip exists and there are no products in the product list try a detail query
        if (count($products) === 0 && $tipProductCode !== null) {
            $result[] = $this->queryDetailIntl($tipProductCode, $lightweight);
        }

        foreach ($products as $product) {
            $result[] = $this->getPartDetailIntl($product, $lightweight);
        }

        return $result;
    }

    /**
     * Sanitizes a field by removing any HTML tags and other unwanted characters
     * @param  string|null  $field
     * @return string|null
     */
    private function sanitizeField(?string $field): ?string
    {
        if ($field === null) {
            return null;
        }
        // Replace "range" indicators with mathematical tilde symbols
        // so they don't get rendered as strikethrough by Markdown
        $field = preg_replace("/~/", "\u{223c}", $field);

        return strip_tags($field);
    }


    /**
     * Takes a deserialized json object of the product and returns a PartDetailDTO
     * @param  array  $product
     * @return PartDetailDTO
     */
    private function getPartDetailIntl(array $product, bool $lightweight = false): PartDetailDTO
    {
        // Get product images in advance
        $product_images = $this->getProductImages($product['productImages'] ?? null);
        $product['productImageUrl'] ??= null;

        // If the product does not have a product image but otherwise has attached images, use the first one.
        if (count($product_images) > 0) {
            $product['productImageUrl'] ??= $product_images[0]->url;
        }

        // LCSC puts HTML in footprints and descriptions sometimes randomly
        $footprint = $product["encapStandard"] ?? null;
        //If the footprint just consists of a dash, we'll assume it's empty
        if ($footprint === '-') {
            $footprint = null;
        }

        //Build category by concatenating the catalogName and parentCatalogName
        $category = $product['parentCatalogName'] ?? null;
        if (isset($product['catalogName'])) {
            $category = ($category ?? '') . ' -> ' . $product['catalogName'];
        }
        $a = new PartDetailDTO(
            provider_key: $this->getProviderKey(),
            provider_id: $product['productCode'],
            name: $product['productModel'],
            description: $this->sanitizeField($product['productIntroEn']),
            category: $this->sanitizeField($category ?? null),
            manufacturer: $this->sanitizeField($product['brandNameEn'] ?? null),
            mpn: $this->sanitizeField($product['productModel'] ?? null),
            preview_image_url: $product['productImageUrl'],
            manufacturing_status: null,
            provider_url: $this->getProductShortURL($product['productCode']),
            footprint: $this->sanitizeField($footprint),
            datasheets: $lightweight ? [] : $this->getProductDatasheets($product['pdfUrl'] ?? null),
            images: $product_images, // Always include images - users need to see them
            parameters: $lightweight ? [] : $this->attributesToParameters($product['paramVOList'] ?? []),
            vendor_infos: $lightweight ? [] : $this->pricesToVendorInfo($product['productCode'], $this->getProductShortURL($product['productCode']), $product['productPriceList'] ?? []),
            mass: $product['weight'] ?? null,
        );
        return $a;
    }

    /**
     * Converts the price array to a VendorInfoDTO array to be used in the PartDetailDTO
     * @param  string $sku
     * @param  string $url
     * @param  array  $prices
     * @return array
     */
    private function pricesToVendorInfo(string $sku, string $url, array $prices): array
    {
        $price_dtos = [];

        foreach ($prices as $price) {
            $price_dtos[] = new PriceDTO(
                minimum_discount_amount: $price['ladder'],
                price: $price['productPrice'],
                currency_iso_code: $this->getUsedCurrency($price['currencySymbol']),
                includes_tax: false,
            );
        }

        return [
            new PurchaseInfoDTO(
                distributor_name: self::DISTRIBUTOR_NAME,
                order_number: $sku,
                prices: $price_dtos,
                product_url: $url,
            )
        ];
    }

    /**
     * Converts LCSC currency symbol to an ISO code.
     * @param  string  $currency
     * @return string
     */
    private function getUsedCurrency(string $currency): string
    {
        //Decide based on the currency symbol
        return match ($currency) {
            'US$', '$' => 'USD',
            '€' => 'EUR',
            'A$' => 'AUD',
            'C$' => 'CAD',
            '£' => 'GBP',
            'HK$' => 'HKD',
            'JP¥' => 'JPY',
            'RM' => 'MYR',
            'S$' => 'SGD',
            '₽' => 'RUB',
            'kr' => 'SEK',
            'kr.' => 'DKK',
            '₹' => 'INR',
            //Fallback to the configured currency
            default => $this->settings->currency,
        };
    }

    /**
     * Returns a valid LCSC product short URL from product code
     * @param  string  $product_code
     * @return string
     */
    private function getProductShortURL(string $product_code): string
    {
        return 'https://www.lcsc.com/product-detail/' . $product_code . '.html';
    }

    /**
     * Returns a product datasheet FileDTO array from a single pdf url
     * @param  string  $url
     * @return FileDTO[]
     */
    private function getProductDatasheets(?string $url): array
    {
        if ($url === null) {
            return [];
        }

        $realUrl = $this->getRealDatasheetUrl($url);

        return [new FileDTO($realUrl, null)];
    }

    /**
     * Returns a FileDTO array with a list of product images
     * @param  array|null  $images
     * @return FileDTO[]
     */
    private function getProductImages(?array $images): array
    {
        return array_map(static fn($image) => new FileDTO($image), $images ?? []);
    }

    /**
     * @param  array|null  $attributes
     * @return ParameterDTO[]
     */
    private function attributesToParameters(?array $attributes): array
    {
        $result = [];

        foreach ($attributes as $attribute) {

            //Skip this attribute if it's empty
            if (in_array(trim((string) $attribute['paramValueEn']), ['', '-'], true)) {
                continue;
            }

            $result[] = ParameterDTO::parseValueIncludingUnit(name: $attribute['paramNameEn'], value: $attribute['paramValueEn'], group: null);
        }

        return $result;
    }

    public function searchByKeyword(string $keyword): array
    {
        return $this->queryByTerm($keyword, true); // Use lightweight mode for search
    }

    /**
     * Batch search multiple keywords asynchronously (like JavaScript Promise.all)
     * @param array $keywords Array of keywords to search
     * @return array Results indexed by keyword
     */
    public function searchByKeywordsBatch(array $keywords): array
    {
        if ($this->isChinaOrigin()) {
            return $this->searchByKeywordsBatchChina($keywords);
        }

        return $this->searchByKeywordsBatchIntl($keywords);
    }

    private function searchByKeywordsBatchChina(array $keywords): array
    {
        $rawResults = $this->szlcscClient->getProductInfoByKeywordsBatch($keywords);

        $results = [];
        foreach ($rawResults as $keyword => $products) {
            $results[$keyword] = [];

            foreach ($products as $product) {
                $results[$keyword][] = $this->getPartDetailChina($product, true);
            }
        }

        return $results;
    }


    public function searchByKeywordsBatchIntl(array $keywords): array
    {
        if (empty($keywords)) {
            return [];
        }

        $responses = [];
        $results = [];

        // Start all requests immediately (like JavaScript promises without await)
        foreach ($keywords as $keyword) {
            if (preg_match('/^C\d+$/i', trim($keyword))) {
                // Direct detail API call for C-codes
                $responses[$keyword] = $this->lcscClient->request('GET', self::ENDPOINT_URL . "/product/detail", [
                    'headers' => [
                        'Cookie' => new Cookie('currencyCode', $this->settings->currency)
                    ],
                    'query' => [
                        'productCode' => trim($keyword),
                    ],
                ]);
            } else {
                // Search API call for other terms
                $responses[$keyword] = $this->lcscClient->request('POST', self::ENDPOINT_URL . "/search/v2/global", [
                    'headers' => [
                        'Cookie' => new Cookie('currencyCode', $this->settings->currency)
                    ],
                    'json' => [
                        'keyword' => $keyword,
                    ],
                ]);
            }
        }

        // Now collect all results (like .then() in JavaScript)
        foreach ($responses as $keyword => $response) {
            try {
                $arr = $response->toArray(); // This waits for the response
                $results[$keyword] = $this->processSearchResponse($arr, $keyword);
            } catch (\Exception $e) {
                $results[$keyword] = []; // Empty results on error
            }
        }

        return $results;
    }

    private function processSearchResponse(array $arr, string $keyword): array
    {
        $result = [];

        // Check if this looks like a detail response (direct C-code lookup)
        if (isset($arr['result']['productCode'])) {
            $product = $arr['result'];
            $result[] = $this->getPartDetail($product, true); // lightweight mode
        } else {
            // This is a search response
            $products = $arr['result']['productSearchResultVO']['productList'] ?? [];
            $tipProductCode = $arr['result']['tipProductDetailUrlVO']['productCode'] ?? null;

            // If no products but has tip, we'd need another API call - skip for batch mode
            foreach ($products as $product) {
                $result[] = $this->getPartDetail($product, true); // lightweight mode
            }
        }

        return $result;
    }

    public function getDetails(string $id): PartDetailDTO
    {
        return $this->queryDetail($id, false);
    }

    public function getCapabilities(): array
    {
        return [
            ProviderCapabilities::BASIC,
            ProviderCapabilities::PICTURE,
            ProviderCapabilities::DATASHEET,
            ProviderCapabilities::PRICE,
            ProviderCapabilities::FOOTPRINT,
        ];
    }

    public function getHandledDomains(): array
    {
        return ['lcsc.com'];
    }

    public function getIDFromURL(string $url): ?string
    {
        //Input example: https://www.lcsc.com/product-detail/C258144.html?s_z=n_BC547
        //The part between the "C" and the ".html" is the unique ID

        $matches = [];
        if (preg_match("#/product-detail/(\w+)\.html#", $url, $matches) > 0) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Checks if the provider is configured to use the China-origin API or the international one.
     */
    private function isChinaOrigin(): bool
    {
        return $this->settings->origin === 'china';
    }

    private function queryByTermChina(string $term, bool $lightweight = false): array
    {
        $keyword = trim($term);
        if ($keyword === '') {
            return [];
        }

        $products = $this->szlcscClient->getProductInfoByKeyword($keyword);

        $result = [];
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }

            $result[] = $this->getPartDetailChina($product, $lightweight);
        }

        return $result;
    }

    private function queryDetailChina(string $cCode, bool $lightweight = false): PartDetailDTO
    {
        $product = $this->szlcscClient->getProductInfoByCCode(trim($cCode));

        if ($product === null) {
            throw new \RuntimeException('Could not find SZLCSC product code: ' . $cCode);
        }

        // 在商品详情我们一般会希望看到大图，而不是小的预览图
        $product["basic"]["image"] = "";
        return $this->getPartDetailChina($product, $lightweight);
    }

    /**
     * Unified detail entrypoint.
     */
    private function queryDetail(string $id, bool $lightweight = false): PartDetailDTO
    {
        if ($this->isChinaOrigin()) {
            return $this->queryDetailChina($id, $lightweight);
        }

        return $this->queryDetailIntl($id, $lightweight);
    }

    private function getChinaDetailImages(array $detail): array
    {
        $image = $detail['image'] ?? null;

        if (!is_string($image) || trim($image) === '') {
            return [];
        }

        $parts = explode('<$>', $image);

        return array_values(array_filter(
            array_map(static fn(string $item) => trim($item), $parts),
            static fn(string $item) => $item !== ''
        ));
    }

    private function getChinaDetailImageFiles(array $detail): array
    {
        $images = $this->getChinaDetailImages($detail);

        return array_map(
            static fn(string $url) => new FileDTO($url, null),
            $images
        );
    }

    /**
     * Unified search entrypoint.
     *
     * @return PartDetailDTO[]
     */
    private function queryByTerm(string $term, bool $lightweight = false): array
    {
        if ($this->isChinaOrigin()) {
            return $this->queryByTermChina($term, $lightweight);
        }

        return $this->queryByTermIntl($term, $lightweight);
    }

    private function getPartDetailChina(array $product, bool $lightweight = false): PartDetailDTO
    {
        $basic = $product['basic'] ?? [];
        $detail = $product['detail'] ?? [];

        $images = $this->getChinaDetailImageFiles($detail);

        $previewImage = $basic['image'];

        if (($previewImage === null || trim((string) $previewImage) === '') && isset($images[0]->url) && is_string($images[0]->url)) {
            $previewImage = $images[0]->url;
        }

        $parameters = [];
        if (!$lightweight) {
            foreach (($detail['param'] ?? []) as $name => $value) {
                if (in_array(trim((string) $value), ['', '-'], true)) {
                    continue;
                }

                $parameters[] = ParameterDTO::parseValueIncludingUnit(
                    name: (string) $name,
                    value: (string) $value,
                    group: null,
                );
            }
        }

        $categoryPath = null;
        if (isset($basic['catalogId'])) {
            $categoryPath = $this->szlcscClient->getCatalogPathById((int) $basic['catalogId']);
        }

        $price_dtos = [];
        $prices = $basic["priceList"];
        foreach ($prices as $price) {
            $price_dtos[] = new PriceDTO(
                minimum_discount_amount: $price['startNumber'],
                price: (string)$price['price'],
                currency_iso_code: "CNY", // 立创商城不支持其他货币
                includes_tax: true,       // 立创商城都是默认含税价格
            );
        }
        $vender_infos = [
            new PurchaseInfoDTO(
                distributor_name: "立创商城",
                order_number: $basic["code"],
                prices: $price_dtos,
                product_url: "https://item.szlcsc.com/{$basic["id"]}.html",
            )
        ];

        return new PartDetailDTO(
            provider_key: $this->getProviderKey(),
            provider_id: (string) ($basic['code'] ?? ''),
            name: (string) ($basic['model'] ?? ''),
            description: $this->sanitizeField($detail['name'] ?? $basic['name'] ?? null),
            category: $this->sanitizeField($categoryPath ?? null),
            manufacturer: $this->sanitizeField($basic['brandName'] ?? null),
            mpn: $this->sanitizeField($basic['model'] ?? null),
            preview_image_url: $previewImage,
            manufacturing_status: null,
            provider_url: $basic['detail_url'] ?? null,
            footprint: $this->sanitizeField($basic['standard'] ?? null),
            datasheets: $lightweight ? [] : $this->getChinaDatasheets($basic,$detail),
            images: $images,
            parameters: $parameters,
            vendor_infos: $lightweight ? [] : $vender_infos,
            mass: $basic['productWeight'] * 1000 ?? null, // Convert kg to g
        );
    }

    /**
     * Unified raw-product mapping entrypoint.
     */
    private function getPartDetail(array $product, bool $lightweight = false): PartDetailDTO
    {
        if ($this->isChinaOrigin()) {
            return $this->getPartDetailChina($product, $lightweight);
        }

        return $this->getPartDetailIntl($product, $lightweight);
    }

    private function extractNextData(string $html): array
    {
        if (!preg_match('/<script[^>]*id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/s', $html, $matches)) {
            return [];
        }

        $json = $matches[1] ?? '';
        if (!is_string($json) || trim($json) === '') {
            return [];
        }

        $data = json_decode($json, true);

        if (!is_array($data)) {
            return [];
        }

        return $data['props']['pageProps'] ?? [];
    }

    private function getChinaDatasheets(array $basic, array $detail): array
    {
        $pdfUrl = $detail['pdfUrl'] ?? null;
        $model = $detail['model'] ?? $basic['model'] ?? null;
        $id = $detail['id'] ?? $basic['id'] ?? null;

        // The original datasheet URL format was "https://atta.szlcsc.com/{$detail["pdfUrl"]}", but that link has cross-site access restrictions.
        // When accessed directly from Part-DB, a Referer header is sent, so the URL cannot be opened properly.
        // Therefore, a wrapped HTML datasheet link is used instead.
        if (
            is_string($pdfUrl) && trim($pdfUrl) !== ''
            && is_string($model) && trim($model) !== ''
            && $id !== null && (string) $id !== ''
        ) {
            return [
                new FileDTO(
                    'https://item.szlcsc.com/datasheet/' . rawurlencode((string) $model) . '/' . rawurlencode((string) $id) . '.html',
                    null
                )
            ];
        }

        $cCode = $basic['code'] ?? null;
        $productId = $detail['id'] ?? $basic['id'] ?? null;

        if (!is_string($cCode) || trim($cCode) === '' || $productId === null || (string) $productId === '') {
            return [];
        }

        try {
            $detailUrl = 'https://item.szlcsc.com/' . $productId . '.html';
            $response = $this->lcscClient->request('GET', $detailUrl, [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36',
                    'Referer' => 'https://www.szlcsc.com/',
                ],
            ]);

            $pageProps = $this->extractNextData($response->getContent(false));
            $annexNumber = $pageProps['webData']['productRecord']['pdfDESProductId'] ?? null;

            if (!is_string($annexNumber) || trim($annexNumber) === '') {
                return [];
            }

            $annexes = $this->szlcscClient->getProductAnnexList($cCode, $annexNumber);

            $datasheets = [];
            $seen = [];

            foreach ($annexes as $annex) {
                if (!is_array($annex)) {
                    continue;
                }

                $type = $annex['type'] ?? '';
                $url = $annex['url'] ?? null;
                $name = $annex['name'] ?? null;

                if (!is_string($url) || trim($url) === '') {
                    continue;
                }

                if (!in_array($type, ['pdf_link', 'pdf_property'], true)) {
                    continue;
                }

                $url = trim($url);
                $key = $url;

                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $datasheets[] = new FileDTO(
                    $url,
                    is_string($name) && trim($name) !== '' ? $name : null
                );
            }

            return $datasheets;
        } catch (\Throwable) {
        }

        return [];
    }
}

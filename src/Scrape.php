<?php

// Github:ChesterOfTheSemester

namespace App;

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

require 'vendor/autoload.php';

class Scrape
{
    public function run(): void
    {
        $base_url = 'https://www.magpiehq.com/developer-challenge/smartphones';
        $products_collection = [];

        // Fetch the first page and detect all relevant pagination links
        $first_page_html = $this->fetchPageContent($base_url);
        $crawler = new Crawler($first_page_html);

        $page_numbers = $crawler->filter('#pages a')->each(function (Crawler $node) { return (int) trim($node->text()); });
        $page_numbers = array_unique($page_numbers);
        sort($page_numbers);

        // Gather products from each page in turn
        foreach ($page_numbers as $page_number) {
            $html = $this->fetchPageContent($base_url.'?page='.$page_number);
            $products_collection = $this->extractProducts($html, $products_collection);
        }

        file_put_contents('output.json', json_encode(array_values($products_collection), JSON_PRETTY_PRINT));
    }

    private function fetchPageContent(string $url): string
    {
        $client = new Client();
        $response = $client->get($url);
        return (string) $response->getBody();
    }

    private function extractProducts(string $html, array $products_collection): array
    {
        $crawler = new Crawler($html);

        $crawler->filter('.product')->each(function (Crawler $product_node) use (&$products_collection) {
            // Basic product details
            $title = trim($product_node->filter('.product-name')->text());
            $capacity_text = strtoupper(trim($product_node->filter('.product-capacity')->text()));
            $image_path = $product_node->filter('img')->attr('src');
            $image_url = str_replace('../', 'https://www.magpiehq.com/developer-challenge/', $image_path);
            $price_text = trim($product_node->filter('.text-center.text-lg')->text());
            $price = (float) str_replace(['£', ','], '', $price_text);

            // Convert capacity to MB if specified in GB
            $capacity_mb = (int) filter_var($capacity_text, FILTER_SANITIZE_NUMBER_INT);
            if (strpos($capacity_text, 'GB') !== false) $capacity_mb *= 1024;

            // Capture all colour variants
            $colours = $product_node->filter('span[data-colour]')
                ->each(fn(Crawler $node) => trim($node->attr('data-colour')))
                ?: [null];

            // Check availability details
            $availability_block = $product_node->filter('.my-4.text-sm.block.text-center');
            $availability_text = $availability_block->count()
                ? trim(str_ireplace('Availability:', '', $availability_block->first()->text()))
                : '';
            $is_available = stripos($availability_text, 'in stock') !== false;

            // Examine shipping or delivery notes for a date
            $shipping_text = '';
            $shipping_date = '';
            if ($availability_block->count() > 1) {
                $shipping_text = trim($availability_block->eq(1)->text());

                // Exact: YYYY-MM-DD format
                if (preg_match('/\d{4}-\d{2}-\d{2}/', $shipping_text, $m))
                    $shipping_date = $m[0];

                // Example: "27 Feb 2025" or "27th Feb 2025"
                elseif (preg_match('/(\d{1,2})(?:st|nd|rd|th)?\s+([A-Za-z]+)\s+(\d{4})/', $shipping_text, $m)) {
                    $date_str = "{$m[1]} {$m[2]} {$m[3]}";
                    $dt = \DateTime::createFromFormat('j M Y', $date_str);
                    if ($dt instanceof \DateTime) $shipping_date = $dt->format('Y-m-d');
                }

                // Match "tomorrow"
                elseif (stripos($shipping_text, 'tomorrow') !== false)
                    $shipping_date = (new \DateTime('tomorrow'))->format('Y-m-d');
            }

            // Deduplicate based on title, capacity, and colour
            foreach ($colours as $colour) {
                $unique_key = "{$title}|{$capacity_mb}|" . ($colour ?? 'no-colour');
                $products_collection[$unique_key] = new Product(
                    $title,
                    $price,
                    $image_url,
                    $capacity_mb,
                    $colour,
                    $availability_text,
                    $is_available,
                    $shipping_text,
                    $shipping_date
                );
            }
        });

        return $products_collection;
    }
}

$scrape = new Scrape();
$scrape->run();

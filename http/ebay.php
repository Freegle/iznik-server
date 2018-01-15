<?php

# This allows us to fetch RSS feeds from eBay without hitting CORS errors in the browser.

$url = "http://rest.ebay.com/epn/v1/find/item.rss" . substr($_SERVER['REQUEST_URI'], strpos($_SERVER['REQUEST_URI'], '?'));

$data = file_get_contents($url);

echo $data;
<?php

# This allows us to fetch RSS feeds without hitting CORS errors in the browser.
$data = file_get_contents("https://medium.com/feed/@edwardhibbert");

echo $data;

<?php

define('ROOT', __DIR__);
define('DS', DIRECTORY_SEPARATOR);
define('DIST_DIR', ROOT . DS . 'dist');
define('EXCEL_FILES_DIR_NAME', 'excels');
define('EXCEL_FILES_DIR', ROOT . DS . EXCEL_FILES_DIR_NAME);

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

$files = glob(EXCEL_FILES_DIR . '/*.xlsx', GLOB_NOSORT);
$files_count = count($files);
$file_index = 1;

$provinces = [];
$districts = [];
$wards     = [];
$streets   = [];

function exportContent(string $fileName, $data)
{
    $writeData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);
    file_put_contents(DIST_DIR . DS . $fileName, $writeData);
}

foreach ($files as $file) {
    // echo implode(' ', ['Import', $file_index++ . '/' . $files_count . ': ', $file]) . "\n";

    // $reader = new XlsReader();
    // $spreadsheet = $reader->load($file);

    // $xlsxFilePath = str_replace('xls', 'xlsx', $file);

    // $writer = new XlsxWriter($spreadsheet);
    // $writer->save($xlsxFilePath);

    \Spatie\SimpleExcel\SimpleExcelReader::create($file)
        ->formatHeadersUsing(fn ($header) => \Illuminate\Support\Str::slug($header))
        ->getRows()
        ->each(function (array $rowProperties) use (&$provinces, &$districts, &$wards) {

            $provinces[$rowProperties['ma-tp']] = [
                'name' => $rowProperties['tinh-thanh-pho'],
                'slug' => \Illuminate\Support\Str::slug($rowProperties['tinh-thanh-pho']),
                'code' => $rowProperties['ma-tp']
            ];

            $districts[$rowProperties['ma-qh']] = [
                'name' => $rowProperties['quan-huyen'],
                'slug' => \Illuminate\Support\Str::slug($rowProperties['quan-huyen']),
                'code' => $rowProperties['ma-qh'],
                'province_code' => $rowProperties['ma-tp'],
            ];

            if ($rowProperties['ma-px'] != '') {
                $wards[$rowProperties['ma-px']] = [
                    'name' => $rowProperties['phuong-xa'],
                    'slug' => \Illuminate\Support\Str::slug($rowProperties['phuong-xa']),
                    'code' => $rowProperties['ma-px'],
                    'district_code' => $rowProperties['ma-qh'],
                ];
            }
        });
}

$client = new Client();

$requests = function ($w) {
    foreach ($w as $ward) {
        $uri = 'https://location.okd.viettelpost.vn/location/v1.0/autocomplete?system=VTP&ctx=SUBWARD&ctx=' . $ward['code'];
        yield $ward['code'] => new Request('GET', $uri);
    }
};

$pool = new Pool($client, $requests($wards), [
    'concurrency' => 100,
    'fulfilled' => function (Response $response, $index) use (&$streets) {
        $content = $response->getBody()->getContents();

        foreach (json_decode($content, true) as $street) {
            $streets[$street['id']] = [
                'name' => $street['name'],
                'slug' => \Illuminate\Support\Str::slug($street['name']),
                'code' => $street['id'],
                'district_code' => $index,
            ];
        }
    }
]);

// Initiate the transfers and create a promise
$promise = $pool->promise();

// Force the pool of requests to complete.
$promise->wait();


ksort($provinces, SORT_NUMERIC);
ksort($districts, SORT_NUMERIC);
ksort($wards, SORT_NUMERIC);
ksort($streets, SORT_NUMERIC);

exportContent('provinces.json', $provinces);
exportContent('districts.json', $districts);
exportContent('wards.json', $wards);
exportContent('streets.json', $streets);

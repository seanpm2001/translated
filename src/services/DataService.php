<?php
/**
 * translated plugin for Craft CMS 3.x
 *
 * Request translations via translated from the comfort of your dashboard
 *
 * @link      https://scaramanga.agency
 * @copyright Copyright (c) 2021 Scaramanga Agency
 */

namespace scaramangagency\translated\services;

use scaramangagency\translated\Translated;
use scaramangagency\translated\services\fields\MatrixField;
use scaramangagency\translated\services\fields\NeoField;
use scaramangagency\translated\services\fields\StandardField;
use scaramangagency\translated\services\fields\SupertableField;

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\Csv as ReaderCsv;
use PhpOffice\PhpSpreadsheet\Writer\Csv as WriterCsv;

use Craft;
use craft\base\Component;

/**
 * @author    Scaramanga Agency
 * @package   Translated
 * @since     1.0.0
 */
class DataService extends Component
{
    public function generateCSVForTranslation($element)
    {
        $settings = translated::$plugin->getSettings();

        $data = $this->getDataFromElement($element);
        $data['title'] = $element->title;

        if ($settings['translateSlugs']) {
            $data['slug'] = $element->slug;
        }

        $prepared = $this->flatten($data);
        $filepath = Craft::$app->getPath()->getTempAssetUploadsPath();
        $filename = 'translated_autogenerated_' . $element->slug . '_' . time() . '.csv';

        $fp = fopen($filepath . DIRECTORY_SEPARATOR . $filename, 'w');
        fputcsv($fp, ['HANDLE', 'RAW', 'TRANSLATED']);
        foreach ($prepared as $key => $value) {
            fputcsv($fp, [$key, preg_replace("/\r|\n/", ' ', $value), '']);
        }
        fclose($fp);

        $settings = translated::$plugin->getSettings();

        $volume =
            Craft::$app->volumes->getVolumeById($settings['translatedAutogenerationDirectory']) ??
            Craft::$app->volumes->getAllVolumes()[0];

        $folder = Craft::$app->assets->getFolderTreeByVolumeIds([$volume->id])[0];

        $asset = new \craft\elements\Asset();
        $asset->tempFilePath = $filepath . DIRECTORY_SEPARATOR . $filename;
        $asset->filename = $filename;
        $asset->newFolderId = $folder->id;
        $asset->volumeId = $volume->id;
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(\craft\elements\Asset::SCENARIO_CREATE);
        $result = Craft::$app->getElements()->saveElement($asset);

        if ($result) {
            return ['uploaded' => true, 'path' => $asset];
        } else {
            return ['uploaded' => false, 'path' => $filename];
        }
    }

    public function updateEntryFromTranslationCSV($data)
    {
        $order = translated::$plugin->orderService->getOrder($data['id']);

        if (!$order) {
            Craft::$app->getSession()->setError(Craft::t('app', 'Failed to get order'));
            return false;
        }

        $element = Craft::$app->getElements()->getElementById($data['entryId'], null);
        $originalElement = $this->getDataFromElement($element);

        $deliveryBlob = base64_decode($order['translatedContent']);

        $filepath = Craft::$app->getPath()->getTempAssetUploadsPath();
        $filename = 'tmp_' . $data['id'] . '.csv';
        $fp = fopen($filepath . DIRECTORY_SEPARATOR . $filename, 'w');
        fputs($fp, $deliveryBlob);
        fclose($fp);

        $reader = new Xlsx();
        $spreadsheet = @$reader->load($filepath . DIRECTORY_SEPARATOR . $filename) ?? null;
        $writer = new WriterCsv($spreadsheet);
        try {
            $writer->save($filepath . DIRECTORY_SEPARATOR . 'csv_' . $filename);
        } catch (\PhpOffice\PhpSpreadsheet\Exception $e) {
            return false;
        }
        $reader = new ReaderCsv();
        $lines = $reader
            ->load($filepath . DIRECTORY_SEPARATOR . 'csv_' . $filename)
            ->getActiveSheet()
            ->toArray();

        foreach ($lines as $line) {
            if ($line[0] == 'HANDLE') {
                continue;
            }

            $fieldHandle = explode('.', $line[0]);

            if (sizeof($fieldHandle) == 1) {
                $originalElement[$line[0]] = $line[2];
            } else {
                if (strpos($line[0], 'enabled') || strpos($line[0], 'collapsed')) {
                    $this->setValueByDot($originalElement, $line[0], $line[1]);
                } else {
                    $this->setValueByDot($originalElement, $line[0], $line[2]);
                }
            }
        }

        unlink($filepath . DIRECTORY_SEPARATOR . 'csv_' . $filename);
        unlink($filepath . DIRECTORY_SEPARATOR . $filename);

        return $originalElement;
    }

    public function getWordCount($element)
    {
        $wordCount = 0;

        $wordCount += str_word_count($element->title);
        $wordCount += str_word_count($element->slug);

        foreach ($element->getFieldLayout()->getFields() as $layoutField) {
            $field = Craft::$app->fields->getFieldById($layoutField->id);

            if ($field instanceof \craft\fields\Matrix) {
                $wordCount += MatrixField::getMatrixDataWordCount($element, $field);
            }

            if ($field instanceof \verbb\supertable\fields\SuperTableField) {
                $wordCount += SupertableField::getSupertableDataWordCount($element, $field);
            }

            if ($field instanceof \benf\neo\Field) {
                $wordCount += NeoField::getNeoDataWordCount($element, $layoutField);
            }

            if ($field->getIsTranslatable()) {
                if ($field instanceof \craft\fields\PlainText || $field instanceof \craft\redactor\Field) {
                    $wordCount += StandardField::getStandardDataWordCount($element, $field);
                }
            }
        }

        return $wordCount;
    }

    public function getDataFromElement($element)
    {
        $data = [];

        foreach ($element->getFieldLayout()->getFields() as $layoutField) {
            $field = Craft::$app->fields->getFieldById($layoutField->id);

            if ($field instanceof \craft\fields\Matrix) {
                $data = array_merge($data, MatrixField::decorateMatixData($element, $layoutField));
            }

            if ($field instanceof \verbb\supertable\fields\SuperTableField) {
                $data = array_merge($data, SupertableField::decorateSupertableData($element, $layoutField));
            }

            if ($field instanceof \benf\neo\Field) {
                $data = array_merge($data, NeoField::decorateNeoData($element, $layoutField));
            }

            if ($field->getIsTranslatable()) {
                if ($field instanceof \craft\fields\PlainText || $field instanceof \craft\redactor\Field) {
                    $tmp = StandardField::decorateStandardData($element, $layoutField);

                    if ($tmp) {
                        $data = array_merge($data, $tmp);
                    }
                }
            }
        }
        return $data;
    }

    private function flatten($array, $prefix = '')
    {
        $result = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $result = $result + $this->flatten($value, $prefix . $key . '.');
            } else {
                if ($key != 'type') {
                    $result[$prefix . $key] = $value;
                }
            }
        }
        return $result;
    }

    private function setValueByDot(&$arr, $path, $value, $separator = '.')
    {
        $keys = explode($separator, $path);

        foreach ($keys as $key) {
            $arr = &$arr[$key];
        }

        $arr = $value;
    }
}

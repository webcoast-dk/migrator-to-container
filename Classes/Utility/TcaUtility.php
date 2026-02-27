<?php

declare(strict_types=1);

namespace WEBcoast\MigratorToContainer\Utility;

use B13\Container\Tca\ContainerConfiguration;
use B13\Container\Tca\Registry;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

class TcaUtility
{
    public static function addColumDefinitionsToTcaOverrides(string $file, array $fields, array $columnsOverrides, string $CType): void
    {
        if (!file_exists($file)) {
            self::createNewTcaOverrides($file);
        }

        $content = GeneralUtility::getUrl($file);
        // Find the `use` statements and check, if `TYPO3\CMS\Core\Utility\ExtensionManagementUtility` is already imported, add it if not
        if (!self::hasImport($content, ExtensionManagementUtility::class)) {
            $content = self::addImport($content, ExtensionManagementUtility::class);
        }

        $fieldsAsPhpCode = rtrim(self::arrayToPhpCode(array_filter($fields, fn ($field) => $field['config'] ?? null)), "\n\r\t\v\0,");
        $newFieldListPosition = 'after';
        // Generate field list with label, if the config is empty
        $fieldList = implode(', ', array_map(function ($field, $fieldName) use (&$newFieldListPosition) {
            if ($fieldName === 'header') {
                $newFieldListPosition = 'replace';
            }
            if (!$field['config']) {
                return $fieldName . ';' . $field['label'];
            }

            return $fieldName;
        }, $fields, array_keys($fields)));

        $content .= <<<TCA

            ExtensionManagementUtility::addTCAcolumns(
                'tt_content',
                {$fieldsAsPhpCode}
            );

            ExtensionManagementUtility::addToAllTCAtypes(
                'tt_content',
                '{$fieldList}',
                '{$CType}',
                '{$newFieldListPosition}:header'
            );
            TCA;

        if (!empty($columnsOverrides)) {
            $columnsOverridesAsPhpCode = trim(self::arrayToPhpCode($columnsOverrides, 0), "\n\r\t\v\0,");

            $content .= <<<TCA

            \$GLOBALS['TCA']['tt_content']['types']['{$CType}']['columnsOverrides'] = {$columnsOverridesAsPhpCode};
            TCA;

        }

        GeneralUtility::writeFile($file, $content);
    }

    protected static function createNewTcaOverrides(string $file): void
    {
        GeneralUtility::mkdir_deep(dirname($file));
        GeneralUtility::writeFile(
            $file,
            <<<'EOF'
                <?php

                declare(strict_types=1);

                if (!defined('TYPO3')) {
                    die('Access denied.');
                }

                EOF
        );
    }

    protected static function arrayToPhpCode(array $array, int $level = 1): string
    {
        $indent = str_repeat('    ', $level);
        $nextIndent = str_repeat('    ', $level + 1);
        $code = "[\n";

        // Check if all keys are integers starting from 0, if so, we can use a simpler syntax without the keys
        $isList = array_keys($array) === range(0, count($array) - 1);

        foreach ($array as $key => $value) {
            // Format the key properly (quotes for strings, no quotes for integers)
            $formattedKey = is_int($key) ? $key : "'" . addslashes($key) . "'";

            // Determine how to format the value
            if (is_array($value)) {
                $formattedValue = self::arrayToPhpCode($value, $level + 1);
            } elseif (is_string($value)) {
                $formattedValue = "'" . addslashes($value) . "'";
            } elseif (is_bool($value)) {
                $formattedValue = $value ? 'true' : 'false';
            } elseif (is_null($value)) {
                $formattedValue = 'null';
            } elseif ($value instanceof \BackedEnum) {
                $formattedValue = "'" . addslashes((string) $value->value) . "'";
            } elseif ($value instanceof \UnitEnum) {
                $formattedValue = "'" . addslashes($value->name) . "'";
            } else {
                $formattedValue = $value;
            }

            $code .= $nextIndent . (!$isList ? $formattedKey . ' => ' : '') . $formattedValue . (!is_array($value) ? ",\n" : '');
        }

        $code .= $indent . "],\n";

        return $code;
    }

    protected static function addImport(string $content, string $classToImport): string
    {
        // Find use statements, and add the new one
        if (preg_match('/^use\s+([a-zA-Z0-9\\\]+);/m', $content, $matches)) {
            // If there are use statements, add the new one after the first one
            $content = preg_replace(
                '/^use\s+([a-zA-Z0-9\\\]+);/m',
                'use $1;' . PHP_EOL . 'use ' . ltrim($classToImport, '\\') . ';',
                $content,
                1
            );
        } else {
            // Check, if there is a strict type declaration
            if (preg_match('/^<\?php\s*declare\(strict_types=1\);/m', $content)) {
                // If there is a strict type declaration, add the import statement after that
                $content = preg_replace(
                    '/^<\?php\s*declare\(strict_types=1\);/m',
                    '<?php' . PHP_EOL . PHP_EOL . 'declare(strict_types=1);' . PHP_EOL . PHP_EOL . 'use ' . ltrim($classToImport, '\\') . ';',
                    $content,
                    1
                );
            } else {
                // If there is no strict type declaration, find the first `<?php` tag and add the import statement after that
                $content = preg_replace(
                    '/^<\?php/m',
                    '<?php' . PHP_EOL . PHP_EOL . 'use ' . ltrim($classToImport, '\\') . ';' . PHP_EOL,
                    $content,
                    1
                );
            }
        }

        return $content;
    }

    public static function writeContainerContentTypeTcaConfiguration(string $file, string $containerCType, string $group, string $title, string $description, array $grid, ?string $iconIdentifier): void
    {
        $content = <<<'EOF'
            <?php

            declare(strict_types=1);

            if (!defined('TYPO3')) {
                die('Access denied.');
            }

            EOF;

        $content = self::addImport($content, GeneralUtility::class);
        $content = self::addImport($content, Registry::class);
        $content = self::addImport($content, ContainerConfiguration::class);

        $columnConfigPhpCode = trim(self::arrayToPhpCode($grid, 3), " \n\r\t\v\0,");

        $iconCode = '';
        if (!empty($iconIdentifier)) {
            $iconCode = <<<ICON

                ->setIcon('{$iconIdentifier}')
            ICON;
        }

        $tca = <<<TCA

            GeneralUtility::makeInstance(Registry::class)->configureContainer(
                (
                    new ContainerConfiguration(
                        '{$containerCType}',
                        '{$title}',
                        '{$description}',
                        {$columnConfigPhpCode}
                    )
                )
                ->setGroup('{$group}'){$iconCode}
            );

            TCA;

        $content .= $tca;

        // Make sure the directory exists
        GeneralUtility::mkdir_deep(dirname($file));
        GeneralUtility::writeFile($file, $content);
    }

    protected static function hasImport(string $content, string $class): bool
    {
        return str_contains($content, 'use ' . ltrim($class, '\\') . ';');
    }

    public static function requireTcaOverrideInFile(string $tcaOverridesFile, string $tcaContentTypeFile): void
    {
        if (!file_exists($tcaOverridesFile)) {
            self::createNewTcaOverrides($tcaOverridesFile);
        }
        $content = GeneralUtility::getUrl($tcaOverridesFile);
        // Generate relative path from tcaOverridesFile to tcaContentTypeFile
        $relativePath = PathUtility::getRelativePath(dirname($tcaOverridesFile), dirname($tcaContentTypeFile)) . basename($tcaContentTypeFile);

        // Check for require_once statement for the given file
        if (!preg_match("#require_once\s+(?:__DIR__\s*\.)?\s*'/{$relativePath}';#", $content)) {
            // If not found, add it at the end of the file
            $content .= "\nrequire_once __DIR__ . '/{$relativePath}';\n";
            GeneralUtility::writeFile($tcaOverridesFile, $content);
        }
    }
}

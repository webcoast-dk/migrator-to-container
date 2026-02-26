<?php

declare(strict_types=1);

namespace WEBcoast\MigratorToContainer\Builder;

use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WEBcoast\Migrator\Builder\AbstractInteractiveContentTypeBuilder;
use WEBcoast\Migrator\Configuration\ContentTypeProviderInterface;
use WEBcoast\Migrator\Migration\FieldType;
use WEBcoast\Migrator\Utility\ArrayUtility;
use WEBcoast\MigratorToContainer\Utility\TcaUtility;
use WEBcoast\MigratorToContainer\Utility\XliffUtility;

class ContainerContentTypeBuilder extends AbstractInteractiveContentTypeBuilder
{
    public function __construct(protected readonly PackageManager $packageManager) {}

    public function getTitle(): string
    {
        return 'Container';
    }

    public function buildContentTypeConfiguration(string $contentTypeName, array $contentTypeConfiguration, ContentTypeProviderInterface $contentTypeProvider): void
    {
        $this->io->section(sprintf('Building container configuration for "%s"', $contentTypeName));

        $availableExtensions = $this->getPossibleExtensions();
        $extensionQuestion = new Question('In which extension, should we place the content block?');
        $extensionQuestion->setAutocompleterValues($availableExtensions);
        $extensionQuestion->setValidator(function ($extension) use ($availableExtensions) {
            if (empty($extension)) {
                throw new \RuntimeException('The extension key must not be empty.');
            }
            if (!in_array($extension, $availableExtensions, true)) {
                throw new \RuntimeException('The extension key "' . $extension . '" is not available. Please choose one of the following: ' . implode(', ', $availableExtensions));
            }

            return $extension;
        });
        $targetExtensionKey = $this->io->askQuestion($extensionQuestion);

        $targetContainerCType = self::buildContentTypeName($contentTypeConfiguration['title']);
        $targetContainerCType = $this->io->ask('What is the name of the content block?', $targetContainerCType, function ($value) {
            if (empty($value) || preg_match('/[^\w_]/', $value)) {
                throw new \RuntimeException('The name of the content block must not be empty and must only container characters, numbers and underscores.');
            }

            return $value;
        });

        $tcaContentTypeFile = GeneralUtility::getFileAbsFileName('EXT:' . $targetExtensionKey . '/Configuration/TCA/ContentTypes/' . $targetContainerCType . '.php');
        if (file_exists($tcaContentTypeFile)) {
            if (!$this->io->askQuestion(new ConfirmationQuestion('The file "' . $tcaContentTypeFile . '" already exists. Do you want to override this file? If you choose "no", this will abort the migration for this provider.', false))) {
                $this->io->block('Aborting the process to avoid overriding existing TCA configuration.', style: 'bg=yellow;fg=black', padding: true);

                return;
            }
        }

        $targetCTypeGroup = $this->io->ask('In which wizard category should the content block be placed?', $contentTypeConfiguration['group'] ?? null ?: 'container', function ($value) {
            if (empty(trim($value))) {
                throw new \RuntimeException('The wizard category must not be empty.');
            }

            return $value;
        });

        $languageFile = 'EXT:' . $targetExtensionKey . '/Resources/Private/Language/locallang_' . $targetContainerCType . '.xlf';
        $languageLabelPrefix = 'LLL:' . $languageFile . ':';
        $labels = [];

        // Extract title and description to language file, if they are not already language labels
        if (!str_starts_with($contentTypeConfiguration['title'], 'LLL:')) {
            $labelKey = 'tt_content.CType.' . $targetContainerCType . '.title';
            $labels[$labelKey] = $contentTypeConfiguration['title'];
            $contentTypeConfiguration['title'] = $languageLabelPrefix . $labelKey;
        }

        if (!str_starts_with($contentTypeConfiguration['description'] ?? '', 'LLL:')) {
            $labelKey = 'tt_content.CType.' . $targetContainerCType . '.description';
            $labels[$labelKey] = $contentTypeConfiguration['description'] ?? '';
            $contentTypeConfiguration['description'] = $languageLabelPrefix . $labelKey;
        }

        // Extract column names to language file, if they are not already language labels
        foreach ($contentTypeConfiguration['grid'] as &$row) {
            foreach ($row as &$column) {
                if (!str_starts_with($column['name'], 'LLL:')) {
                    $labelKey = 'tt_content.' . $targetContainerCType . '.' . $column['name'] . '.label';
                    $labels[$labelKey] = $column['name'];
                    $column['name'] = $languageLabelPrefix . $labelKey;
                }
            }
        }

        $containerFields = $this->buildFieldsConfiguration($contentTypeConfiguration['fields'] ?? []);
        $columnsOverrides = [];
        $fieldIdentifiers = array_column($containerFields, 'identifier');

        // Extract labels and descriptions of fields to language file, if they are not already language labels.
        // If the field should use an existing field, move the configuration of the field to the columns overrides
        $containerFields = array_map(function ($field) use (&$labels, &$columnsOverrides, $targetContainerCType, $targetExtensionKey, $languageLabelPrefix) {
            $fieldName = $field['identifier'];
            $labelOrKey = $field['label'];
            $descriptionOrKey = $field['description'] ?? '';
            $useExistingField = $field['useExistingField'] ?? false;
            unset($field['identifier'], $field['label'], $field['description'], $field['useExistingField']);
            if ($useExistingField) {
                if (!str_starts_with($labelOrKey, 'LLL:')) {
                    $labelKey = 'tt_content.' . $fieldName . '.types.' . $targetContainerCType . '.label';
                    $labels[$labelKey] = $labelOrKey;
                    $labelOrKey = $languageLabelPrefix . $labelKey;
                }
                if (!empty($descriptionOrKey) && !str_starts_with($descriptionOrKey, 'LLL:')) {
                    $descriptionKey = 'tt_content.' . $fieldName . '.types.' . $targetContainerCType . '.description';
                    $labels[$descriptionKey] = $descriptionOrKey;
                    $descriptionOrKey = $languageLabelPrefix . $descriptionKey;
                }
                $columnsOverrides[$fieldName]['config'] = $field;
                $field = null;
            } else {
                if (!str_starts_with($labelOrKey, 'LLL:')) {
                    $labelKey = 'tt_content.' . $fieldName . '.label';
                    $labels[$labelKey] = $labelOrKey;
                    $labelOrKey = $languageLabelPrefix . $labelKey;
                }
                if (!empty($descriptionOrKey) && !str_starts_with($descriptionOrKey, 'LLL:')) {
                    $descriptionKey = 'tt_content.' . $fieldName . '.description';
                    $labels[$descriptionKey] = $descriptionOrKey;
                    $descriptionOrKey = $languageLabelPrefix . $descriptionKey;
                }
            }

            return [
                'label' => $labelOrKey,
                'description' => $descriptionOrKey ?: null,
                'config' => $field
            ];
        }, $containerFields);
        $containerFields = array_combine($fieldIdentifiers, $containerFields);
        $containerFields = ArrayUtility::removeEmptyValuesFromArray($containerFields);

        TcaUtility::writeContainerContentTypeTcaConfiguration($tcaContentTypeFile, $targetContainerCType, $targetCTypeGroup, $contentTypeConfiguration['title'], $contentTypeConfiguration['description'], $contentTypeConfiguration['grid'], $contentTypeConfiguration['iconIdentifier'] ?? null);
        TcaUtility::addColumDefinitionsToTcaOverrides($tcaContentTypeFile, $containerFields, $columnsOverrides, $targetContainerCType);
        $tcaOverridesFile = GeneralUtility::getFileAbsFileName('EXT:' . $targetExtensionKey . '/Configuration/TCA/Overrides/tt_content.php');
        TcaUtility::requireTcaOverrideInFile($tcaOverridesFile, $tcaContentTypeFile);
        $xliffFile = GeneralUtility::getFileAbsFileName($languageFile);
        XliffUtility::addLabelsToFile($xliffFile, $labels, $targetExtensionKey);

        $this->copyTemplate($contentTypeProvider, $contentTypeName, $targetExtensionKey, $targetContainerCType);

        $this->io->block('  Container configuration finished  ', style: 'bg=green;fg=white', padding: true);
    }

    private function buildFieldsConfiguration(array $fields): array
    {
        $containerFields = [];
        foreach ($fields as $field) {
            if ($field['type'] === FieldType::TAB) {
                $this->io->writeln('<b>Tab:</b> ' . $field['title'] . ' (' . $field['identifier'] . ')');
                if ($this->io->askQuestion(new ConfirmationQuestion('Do you want to process this tab?', true))) {
                    $containerFields[] = $this->buildFieldConfiguration($field);
                }
            } else {
                $this->io->writeln('<b>Field:</b> ' . $field['label'] . ' (' . $field['identifier'] . ')');
                if ($this->io->askQuestion(new ConfirmationQuestion('Do you want to process this field?', true))) {
                    $containerFields[] = $this->buildFieldConfiguration($field);
                }
            }
        }

        return $containerFields;
    }

    protected function buildFieldConfiguration(array $field): array
    {
        if ($field['type'] === FieldType::TAB) {
            return [
                'identifier' => $this->io->askQuestion(
                    (new Question('What is the identifier of the tab?', GeneralUtility::camelCaseToLowerCaseUnderscored($field['identifier'])))
                        ->setValidator(function ($value) {
                            if (empty($value)) {
                                throw new \RuntimeException('The identifier of the tab must not be empty.');
                            }

                            return $value;
                        })
                ),
                'type' => 'Tab',
                'label' => $this->io->askQuestion(
                    (new Question('What is the label of the field?', $field['title']))
                        ->setValidator(function ($value) {
                            if (empty($value)) {
                                throw new \RuntimeException('The label of the field must not be empty.');
                            }

                            return $value;
                        })
                )
            ];
        }

        $fieldConfiguration = [
            'identifier' => $this->io->askQuestion(
                (new Question('What is the identifier of the field?', GeneralUtility::camelCaseToLowerCaseUnderscored($field['identifier'])))
                    ->setValidator(function ($value) {
                        if (empty($value)) {
                            throw new \RuntimeException('The identifier of the field must not be empty.');
                        }

                        return $value;
                    })
            ),
            'label' => $this->io->askQuestion(
                (new Question('What is the label of the field?', $field['label']))
                    ->setValidator(function ($value) {
                        if (empty($value)) {
                            throw new \RuntimeException('The label of the field must not be empty.');
                        }

                        return $value;
                    })
            )
        ];

        if ($this->io->askQuestion(new ConfirmationQuestion('Do you want to use an existing field?', false))) {
            $fieldConfiguration['useExistingField'] = true;
        }

        if ($field['type'] !== FieldType::SECTION) {
            $fieldConfiguration['config']['type'] = $field['type'];
            $fieldConfiguration = array_replace_recursive($fieldConfiguration, $field['config'] ?? []);
        } else {
            $this->io->block('The field "' . $field['label'] . '" is a section type. Sections are not supported for container elements?', style: 'bg=error;fg=white', padding: true);
        }

        if ($fieldConfiguration['useExistingField'] ?? false) {
            unset($fieldConfiguration['type']);
        }

        return $fieldConfiguration;
    }

    protected function getPossibleExtensions(): array
    {
        $extensions = [];

        foreach ($this->packageManager->getAvailablePackages() as $package) {
            $extensions[] = $package->getValueFromComposerManifest('extra')?->{'typo3/cms'}->{'extension-key'};
        }

        return $extensions;
    }

    private static function buildContentTypeName(string $title): string
    {
        $title = preg_replace('/\W/', '_', $title);
        $title = preg_replace('/-+/', '_', $title);
        $title = trim($title, '_');

        return strtolower(trim($title));
    }

    public function supports(array $normalizedConfiguration): bool
    {
        return !empty($normalizedConfiguration['grid']);
    }

    protected function copyTemplate(ContentTypeProviderInterface $contentTypeProvider, string $contentTypeName, string $targetExtensionKey, string $targetContainerCType): void
    {
        $templateCode = $contentTypeProvider->getFrontendTemplate($contentTypeName);
        if ($templateCode) {
            $templatePath = GeneralUtility::getFileAbsFileName('EXT:' . $targetExtensionKey . '/Resources/Private/Templates/Content/' . GeneralUtility::underscoredToUpperCamelCase($targetContainerCType) . '.html');
            if (file_exists($templatePath)) {
                if (!$this->io->askQuestion(new ConfirmationQuestion('The file "' . $templatePath . '" already exists. Do you want to override this file? If you choose "no", the template will not be copied.', false))) {
                    $this->io->block('Skipping the template to avoid overriding existing file.', style: 'bg=yellow;fg=black', padding: true);

                    return;
                }
            }
            GeneralUtility::mkdir_deep(dirname($templatePath));
            GeneralUtility::writeFile($templatePath, $templateCode);
        }
    }
}
